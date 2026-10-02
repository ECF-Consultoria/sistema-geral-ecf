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

// ─── Cópia local × servidor (CR-F01) ────────────────────────────────────────
//
// O editor guarda três versões do que é digitado: a do SERVIDOR (a última
// resposta), a LOCAL (o que está na tela) e a BASE — o que o servidor já tem
// da cópia local (a última leitura, ou o último salvamento que deu certo).
// Campo em que a local difere da base é edição que ainda não chegou ao
// servidor: nenhuma resposta pode pisar nele, e ele continua por salvar.

/**
 * Igualdade profunda dos dados do rascunho. `null` e `undefined` contam como o
 * mesmo vazio, e lista vazia é igual a objeto vazio (o PHP manda `[]` para mapa vazio).
 */
export const iguais = (a, b) => {
    if (a === b) return true;
    if (a === null || a === undefined || b === null || b === undefined) return (a ?? null) === (b ?? null);
    if (typeof a !== 'object' || typeof b !== 'object') return false;
    if (Array.isArray(a) && Array.isArray(b) && a.length !== b.length) return false;
    const chaves = new Set([...Object.keys(a), ...Object.keys(b)]);
    for (const k of chaves) {
        if (! iguais(a[k], b[k])) return false;
    }

    return true;
};

/** Chaves de um objeto em que a cópia local difere da base. */
const chavesDiferentes = (local, base) => [...new Set([...Object.keys(local ?? {}), ...Object.keys(base ?? {})])]
    .filter((k) => ! iguais(local?.[k], base?.[k]));

/** O que do rascunho ainda não chegou ao servidor: `{ chave: valor }` só das chaves de topo editadas; nulo se nada. */
export const envioDoRascunho = (local, base) => {
    if (! local) return null;
    const campos = chavesDiferentes(local, base);

    return campos.length ? Object.fromEntries(campos.map((k) => [k, local[k]])) : null;
};

/** O que das variantes ainda não chegou ao servidor: `{ chave: { campo: valor } }`; nulo se nada. */
export const envioDasVariantes = (local, base) => {
    const envio = {};
    for (const [chave, v] of Object.entries(local ?? {})) {
        const campos = chavesDiferentes(v, base?.[chave]);
        if (campos.length) envio[chave] = Object.fromEntries(campos.map((k) => [k, v[k]]));
    }

    return Object.keys(envio).length ? envio : null;
};

/** Três vias, campo a campo: o editado (local ≠ base) fica; o resto vem do servidor. */
const mesclarCampos = (servidor, local, base) => {
    if (! local) return servidor;
    const r = {};
    for (const k of new Set([...Object.keys(servidor ?? {}), ...Object.keys(local), ...Object.keys(base ?? {})])) {
        const v = iguais(local[k], base?.[k]) ? servidor?.[k] : local[k];
        if (v !== undefined) r[k] = v;
    }

    return r;
};

/**
 * Mescla a resposta de uma ação de estrutura (foto, categoria, variações) — ou de
 * uma releitura — com a cópia local, sem descartar o que foi digitado e ainda não
 * foi salvo (CR-F01). Atributos são decididos um a um, títulos por tipo de anúncio
 * e variantes campo a campo; variante que o servidor não tem mais (eixo mudou) cai.
 *
 * @param {{ servidor: {rasc, vars}, local: {rasc, vars}, base: {rasc, vars} }} versoes
 * @returns {{ rasc: object, vars: object, pendente: { rasc: boolean, vars: boolean } }}
 *   `pendente` = sobrou edição por salvar (o salvamento automático precisa rodar).
 */
export const mesclarComPendentes = ({ servidor, local, base }) => {
    const sr = servidor.rasc;
    if (! local?.rasc || ! base?.rasc) return { rasc: sr, vars: servidor.vars ?? {}, pendente: { rasc: false, vars: false } };

    const lr = local.rasc;
    const br = base.rasc;
    const rasc = {};
    for (const k of new Set([...Object.keys(sr), ...Object.keys(lr), ...Object.keys(br)])) {
        if (k === 'atributos') {
            rasc.atributos = mesclarCampos(sr.atributos ?? {}, lr.atributos ?? {}, br.atributos ?? {});
        } else if (k === 'alvos') {
            rasc.alvos = (sr.alvos ?? []).map((a) => mesclarCampos(
                a,
                (lr.alvos ?? []).find((x) => x.listing_type_id === a.listing_type_id),
                (br.alvos ?? []).find((x) => x.listing_type_id === a.listing_type_id),
            ));
        } else {
            const v = iguais(lr[k], br[k]) ? sr[k] : lr[k];
            if (v !== undefined) rasc[k] = v;
        }
    }

    const vars = Object.fromEntries(Object.entries(servidor.vars ?? {})
        .map(([chave, v]) => [chave, mesclarCampos(v, local.vars?.[chave], base.vars?.[chave])]));

    return {
        rasc,
        vars,
        pendente: { rasc: envioDoRascunho(rasc, sr) !== null, vars: envioDasVariantes(vars, servidor.vars) !== null },
    };
};

// ─── Salvamento automático que falha (WR-F02) ───────────────────────────────

/** Espera antes de cada nova tentativa de um salvamento que falhou (curta, poucas vezes). */
const ESPERAS_NOVA_TENTATIVA = [2000, 5000, 15000];

/** Milissegundos até a tentativa `n` (1 = a primeira depois da falha); nulo = desistiu. */
export const esperaDaNovaTentativa = (n) => ESPERAS_NOVA_TENTATIVA[n - 1] ?? null;

/**
 * O que o indicador da barra mostra. "Salvo" só quando não sobra nada por salvar.
 * @param {{ salvando?: number, pendente?: boolean, falha?: {mensagem: string, desistiu: boolean}|null, pausado?: boolean, salvoEm?: Date|null }} s
 * @returns {'salvando'|'pendente'|'tentando'|'falhou'|'pausado'|'salvo'|null}
 */
export const estadoDoSalvamento = ({ salvando = 0, pendente = false, falha = null, pausado = false, salvoEm = null } = {}) => {
    if (salvando > 0) return 'salvando';
    if (falha) return falha.desistiu ? 'falhou' : 'tentando';
    if (pendente) return pausado ? 'pausado' : 'pendente';

    return salvoEm ? 'salvo' : null;
};

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

/** `secoes` do resumo da IA: o servidor manda NÚMERO (quantas seções preencheu); qualquer outra coisa conta 0. */
const numeroDeSecoes = (resumo) => {
    const n = Number(resumo?.secoes);

    return Number.isInteger(n) && n > 0 ? n : 0;
};

/**
 * Estado da IA a partir do `GET analise.status`: parado | andamento | concluido | erro.
 * `erro` é a mensagem do servidor, ou nula quando ele não disse nada.
 */
export const estadoDaIa = (resposta) => {
    if (! resposta) return { estado: 'parado', etapa: null, texto: null, erro: null, resumo: null };
    if (resposta.status === 'concluido') {
        return { estado: 'concluido', etapa: null, texto: null, erro: null, resumo: resposta.publicador ?? null, secoes: numeroDeSecoes(resposta.publicador), variacoes: resposta.publicador?.variacoes ?? null };
    }
    if (resposta.status === 'erro') {
        return { estado: 'erro', etapa: null, texto: null, erro: resposta.erro || null, resumo: null };
    }

    return { estado: 'andamento', etapa: resposta.etapa ?? null, texto: textoDaEtapa(resposta.etapa), erro: null, resumo: null };
};

/**
 * O que a faixa de conclusão da IA diz (WR-F04), a partir de `resultado.publicador`:
 * - `secoes`: quantas seções a IA preencheu (número);
 * - `aviso`: a explicação do servidor (anúncio publicado, categoria recusada, IA que parou…);
 * - `soPreencheuOVazio`: a pessoa pediu "Substituir", mas editou durante a geração — o
 *   servidor então só preencheu o vazio (`sobrescreveu = false`);
 * - `semVariacoes`: preencheu algo, mas não montou as variações.
 */
export const conclusaoDaIa = (resumo, { pediuSubstituir = false } = {}) => {
    const secoes = numeroDeSecoes(resumo);

    return {
        secoes,
        aviso: resumo?.aviso || null,
        soPreencheuOVazio: pediuSubstituir === true && resumo?.sobrescreveu === false && secoes > 0,
        semVariacoes: secoes > 0 && ! resumo?.variacoes,
    };
};
