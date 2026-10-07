// ═══════════════════════════════════════════════════════════════════════
// "Consultar fretes no Mercado Livre" da lista de Produtos (Fase 167, D-16;
// revisão FE-WR-07).
//
// O servidor aceita até 200 variações por consulta (`variacao_ids` max:200),
// cota 12 por vez e devolve o resto em `pendentes`; a rota aceita 20 consultas
// por minuto. Aqui: blocos de até 200, repetindo enquanto houver pendência, até
// um teto por clique abaixo do limite da rota. O que sobrar continua no clique
// seguinte, porque o que já foi cotado volta do cache do servidor.
//
// Sem React e sem axios: a página injeta `enviar` (o POST, devolve `data`) e
// `aoReceber` (aplica os fretes nas linhas); o teste roda o laço de verdade.
// Nenhuma regra de frete mora aqui: só a ordem das consultas.
// ═══════════════════════════════════════════════════════════════════════

export const BLOCO_FRETES = 200;
export const CONSULTAS_POR_CLIQUE = 15;

/**
 * @returns {Promise<{ resultado: 'ok' | 'parcial' | 'falhou' | 'erro', status: ?number, consultas: number }>}
 *   'parcial': bateu no teto do clique com pendência; 'falhou': o Mercado Livre não respondeu
 *   (insistir no mesmo minuto não adianta); 'erro': o POST lançou (status HTTP, se houver).
 */
export async function consultarFretesEmBlocos(ids, { enviar, aoReceber, bloco = BLOCO_FRETES, teto = CONSULTAS_POR_CLIQUE }) {
    let consultas = 0;
    try {
        for (let i = 0; i < ids.length; i += bloco) {
            const parte = ids.slice(i, i + bloco);
            let pendentes = 1;
            while (pendentes > 0) {
                if (consultas >= teto) return { resultado: 'parcial', status: null, consultas };
                consultas++;
                const data = await enviar(parte);
                aoReceber(data?.fretes ?? {});
                if (data?.falhou) return { resultado: 'falhou', status: null, consultas };
                pendentes = Number(data?.pendentes) || 0;
            }
        }
    } catch (e) {
        return { resultado: 'erro', status: e?.response?.status ?? null, consultas };
    }

    return { resultado: 'ok', status: null, consultas };
}

const FALHA_ML = 'Não deu para consultar o Mercado Livre agora. Os valores continuam como estimativa.';

/** O aviso da lista depois da consulta. 422 e 429 não são "Mercado Livre fora do ar". */
export function avisoDosFretes({ resultado, status }) {
    if (resultado === 'ok') return 'Fretes atualizados.';
    if (resultado === 'parcial') return 'Consultamos parte dos fretes; clique de novo para continuar.';
    if (resultado === 'erro' && status === 429) return 'Muitas consultas seguidas. Espere um minuto e clique de novo para continuar.';
    if (resultado === 'erro' && status === 422) return 'Não deu para consultar estes fretes. Recarregue a página e tente de novo.';

    return FALHA_ML;
}
