// ─── Publicador: derivados puros do editor ──────────────────────────────────
//
// Sem React e sem `@/…`: imports relativos COM extensão, para o `node --test`
// importar este módulo direto. O hook `usePublicador` só chama estas funções.

import { SECOES, estadoDasSecoes, valorVazio } from './apoio.js';

const TIPO_CLASSICO = 'gold_special';
const TIPO_PREMIUM = 'gold_pro';

/** A cópia local (o que está sendo digitado) vence o estado do servidor, campo a campo. */
export const mesclarVariantes = (variantesDoServidor, locais) => (variantesDoServidor ?? []).map((v) => ({ ...v, ...(locais?.[v.chave] ?? {}) }));

export const mesclarAlvos = (alvosDoServidor, alvosLocais) => (alvosDoServidor ?? []).map((a) => ({
    ...a,
    ...((alvosLocais ?? []).find((x) => x.listing_type_id === a.listing_type_id) ?? {}),
}));

/** Pode pedir a conferência: nada em andamento, sem bloqueio local, nada a salvar e com schema. */
export const podeConferir = ({ disabled = false, bloqueiosLocais = 0, salvando = 0, schema = null } = {}) => (
    ! disabled && bloqueiosLocais === 0 && salvando === 0 && !! schema
);

/**
 * Pode publicar: só com conferência do Mercado Livre que ainda vale. Conferência só
 * local (D26) nunca libera, e a conta precisa estar liberada. A trava real é do servidor.
 */
export const podePublicar = ({ disabled = false, conf = null, sujo = false, ciente = false, salvando = 0, liberada = false } = {}) => {
    if (disabled || salvando !== 0 || liberada !== true || ! conf || conf.local === true || sujo) return false;
    if (! conf.vale || ! ['OK', 'AVISOS'].includes(conf.resultado)) return false;

    return conf.resultado === 'OK' || ciente === true;
};

/**
 * Estado da linha de conferência (barra e lateral).
 * `local`/`local_bloqueado` = conferência só local de conta não liberada (D26).
 */
export const estadoDaConferencia = ({ conf = null, aguardando = null, sujo = false } = {}) => {
    if (aguardando?.tipo === 'conferencia') return 'conferindo';
    if (! conf) return 'nao_conferido';
    if (! conf.vale || sujo) return 'editado';
    if (conf.local === true) {
        if (conf.resultado === 'BLOQUEADO') return 'local_bloqueado';

        return conf.resultado === 'ERRO' ? 'erro' : 'local';
    }

    return { OK: 'ok', AVISOS: 'avisos', BLOQUEADO: 'bloqueado', ERRO: 'erro' }[conf.resultado] ?? 'erro';
};

const AVISO_LOCAL = 'A validação no Mercado Livre espera a liberação desta conta.';

export const textoDaConferencia = (estado, nPendencias = 0, liberada = true) => {
    const textos = {
        nao_conferido: liberada ? 'Ainda não conferido no Mercado Livre' : 'Ainda não conferido. Nesta conta a conferência é só local até a liberação.',
        conferindo: liberada ? 'Conferindo cada anúncio com o Mercado Livre…' : 'Conferindo os dados…',
        editado: 'Editado depois da última conferência. Confira de novo.',
        ok: 'Conferido: o Mercado Livre aprovou. Você já pode publicar.',
        avisos: 'Conferido, com avisos do Mercado Livre',
        bloqueado: `O Mercado Livre apontou ${nPendencias} pendência(s)`,
        erro: 'A conferência não terminou. Tente de novo.',
        local: `Conferido aqui: nada falta na ficha. ${AVISO_LOCAL}`,
        local_bloqueado: `A conferência local apontou ${nPendencias} pendência(s). ${AVISO_LOCAL}`,
    };

    return textos[estado] ?? textos.nao_conferido;
};

/** Quantas das 8 seções estão prontas (sem BLOCKER; sem schema só a categoria pode estar). */
export const contarProntas = (problemas, schema) => SECOES.filter((s) => estadoDasSecoes(problemas, schema)[s.chave].completo).length;

/** Anúncios que a publicação cria: tipos ativos × variantes ativas e não órfãs. */
export const totalDeAnuncios = (alvos, variantes) => (alvos ?? []).filter((a) => a.ativo).length * (variantes ?? []).filter((v) => v.ativa && ! v.orfa).length;

const NOME_MODO = { me2: 'Mercado Envios', custom: 'Envio próprio', not_specified: 'A combinar' };

/** Resumo da lateral: por tipo, quantos anúncios e a faixa de preço; total e modo logístico. */
export const resumoDoLancamento = (estado, rasc, variantes, alvos) => {
    const ativas = (variantes ?? []).filter((v) => v.ativa && ! v.orfa);
    const porTipo = (tipo) => {
        const ativo = (alvos ?? []).some((a) => a.listing_type_id === tipo && a.ativo);
        if (! ativo) return { ativo: false, n: 0, min: null, max: null };
        const precos = ativas
            .map((v) => v.precos?.[tipo] ?? v.precos_efetivos?.[tipo] ?? null)
            .filter((p) => p !== null && p !== undefined && p !== '' && Number.isFinite(Number(p)))
            .map(Number);

        return { ativo: true, n: ativas.length, min: precos.length ? Math.min(...precos) : null, max: precos.length ? Math.max(...precos) : null };
    };
    const classico = porTipo(TIPO_CLASSICO);
    const premium = porTipo(TIPO_PREMIUM);

    return {
        modoLogistico: NOME_MODO[rasc?.envio?.modo ?? 'me2'] ?? 'A combinar',
        classico,
        premium,
        total: classico.n + premium.n,
    };
};

/** Há algo preenchido? Decide a confirmação "Substituir o que já está preenchido?" da IA. */
export const rascunhoPreenchido = (estado, rasc) => {
    if (estado?.rascunho?.categoria_id || rasc?.categoria_id) return true;
    if (Object.values(rasc?.atributos ?? {}).some((v) => ! valorVazio(v))) return true;
    if ((rasc?.alvos ?? []).some((a) => String(a.titulo ?? '').trim() !== '')) return true;

    return String(rasc?.descricao ?? '').trim() !== '';
};

// ─── "Anunciar por IA" ──────────────────────────────────────────────────────

const ETAPAS_IA = {
    analise: 'Analisando o produto…',
    titulos: 'Escrevendo títulos…',
    descricao: 'Escrevendo a descrição…',
    ficha: 'Montando a ficha…',
    rascunho: 'Preenchendo o rascunho…',
};

export const textoDaEtapa = (etapa) => ETAPAS_IA[etapa] ?? ETAPAS_IA.analise;

/** Estado da IA a partir do `GET analise.status`: parado | andamento | concluido | erro. */
export const estadoDaIa = (resposta) => {
    if (! resposta) return { estado: 'parado', etapa: null, texto: null, erro: null, resumo: null };
    if (resposta.status === 'concluido') {
        return { estado: 'concluido', etapa: null, texto: null, erro: null, resumo: resposta.publicador ?? null, secoes: resposta.publicador?.secoes ?? null, variacoes: resposta.publicador?.variacoes ?? null };
    }
    if (resposta.status === 'erro') {
        return { estado: 'erro', etapa: null, texto: null, erro: resposta.erro ?? 'Não foi possível concluir. Tente de novo.', resumo: null };
    }

    return { estado: 'andamento', etapa: resposta.etapa ?? null, texto: textoDaEtapa(resposta.etapa), erro: null, resumo: null };
};
