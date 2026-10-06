import { linhaParaServidor } from './produtosEstrutura.js';

// ═══════════════════════════════════════════════════════════════════════
// Sequência do "Salvar produto" da ficha (Fase 167, revisão FE-CR-01).
//
// Produto NOVO nunca vai com `grupo`. No MODO_GRADE o servidor procura o grupo
// entre os produtos que já existem (pelo `codigo` do produto, que não acompanha
// as Refs) e, achando, penduraria a variação num produto alheio — renomeando-o e
// limpando a categoria dele. Por isso a 1ª variação vai SOZINHA e sem grupo (o
// servidor cria um produto) e as demais seguem, em lotes, com o `produto_id` que
// voltou para ela, achado pela `chave`. Se a 1ª não gravar, a sequência PARA:
// mandar as outras soltas criaria um produto por variação.
//
// Produto já gravado (alguma variação com `produto_id`) segue em lotes direto.
//
// Sem React e sem axios: quem chama injeta `enviar` (o POST `linhas`, que
// devolve o `data` do servidor). Assim o teste roda a sequência de verdade
// (tests/js/estrutura-produtos-gravacao.test.js).
// ═══════════════════════════════════════════════════════════════════════

/** O resultado de todos os lotes, no formato que o servidor devolve um. */
export const juntasVazias = () => ({
    linhas: [], erros: [], avisos: [], criadas_nas_listas: { familias: [], ambientes: [] }, listas: null,
});

/**
 * O aviso da ficha quando o POST não respondeu bem (FE-IN-11). O Laravel responde 419 e
 * 429 em inglês ("CSRF token mismatch.", "Too Many Attempts."): esses têm texto próprio.
 * Só a mensagem do 422 (validação, já em português) vai como veio.
 */
export function mensagemDeFalha(e) {
    const status = e?.response?.status;
    if (status === 419) return 'Sua sessão expirou. Recarregue a página; o que você digitou fica guardado para recuperar.';
    if (status === 429) return 'Muitas gravações seguidas. Espere um minuto e tente de novo; o que você digitou fica aqui.';
    if (status === 422 && typeof e.response.data?.message === 'string' && e.response.data.message !== '') return e.response.data.message;
    if (status && status < 500) return 'Não foi possível salvar agora. O que você digitou fica aqui.';

    return 'Não foi possível salvar agora. O que você digitou fica aqui; tente de novo.';
}

/** A ficha nunca manda `grupo`: a linha do servidor traz o código do produto nele. */
const semGrupo = (v) => {
    const { grupo: _grupo, ...resto } = v;

    return resto;
};

/**
 * Grava as variações da ficha.
 *
 * @returns {Promise<{ juntas: object, produtoId: ?number, falha: ?Error, parou: boolean }>}
 *   `falha`: o POST lançou (rede, 4xx/5xx) — `juntas` traz o que os lotes anteriores gravaram;
 *   `parou`: a sequência não chegou ao fim (falha, ou a 1ª variação de um produto novo não gravou).
 */
export async function gravarVariacoes(vars, { enviar, tamanho = 200 }) {
    const juntas = juntasVazias();
    const acumular = (data) => {
        juntas.linhas.push(...(data?.linhas ?? []));
        juntas.erros.push(...(data?.erros ?? []));
        juntas.avisos.push(...(data?.avisos ?? []));
        juntas.criadas_nas_listas.familias.push(...(data?.criadas_nas_listas?.familias ?? []));
        juntas.criadas_nas_listas.ambientes.push(...(data?.criadas_nas_listas?.ambientes ?? []));
        if (data?.listas) juntas.listas = data.listas;
    };

    let produtoId = vars.find((v) => v.produto_id)?.produto_id ?? null;
    let fila = vars;

    try {
        if (! produtoId && vars.length > 0) {
            const [primeira, ...resto] = vars;
            acumular(await enviar([linhaParaServidor(semGrupo(primeira))]));
            produtoId = juntas.linhas.find((l) => l.chave === primeira._k)?.produto_id ?? null;
            if (! produtoId) return { juntas, produtoId: null, falha: null, parou: true };
            fila = resto;
        }

        for (let i = 0; i < fila.length; i += tamanho) {
            const lote = fila.slice(i, i + tamanho).map((v) => semGrupo(v.produto_id ? v : { ...v, produto_id: produtoId }));
            acumular(await enviar(lote.map(linhaParaServidor)));
        }
    } catch (e) {
        return { juntas, produtoId, falha: e, parou: true };
    }

    return { juntas, produtoId, falha: null, parou: false };
}
