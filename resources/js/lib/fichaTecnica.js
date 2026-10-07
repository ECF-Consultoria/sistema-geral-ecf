// ═══════════════════════════════════════════════════════════════════════
// Ficha técnica do produto: funções puras (sem React e sem axios).
//
// A definição dos campos vem do servidor, por categoria: grupos de campos com
// `id`, `nome`, `obrigatorio`, `tipo` (texto, numero, numero_unidade, sim_nao,
// lista), `valores`, `unidades`, `unidade_padrao` e `max`. Aqui só se guarda o
// que a pessoa digitou e se monta o corpo do PUT. Nenhuma regra de validação
// mora aqui: quem confere valor, unidade e obrigatório é o servidor, e o
// motivo volta por campo (`atributos.<id>`).
//
// O `id` do campo é interno: nunca aparece na tela, só o `nome`.
//
// Valores em memória: { [id]: { valor: string, unidade: string } }
// ═══════════════════════════════════════════════════════════════════════

/** Os campos da definição numa lista só, na ordem em que a tela os mostra. */
export function camposDaDefinicao(grupos) {
    return (Array.isArray(grupos) ? grupos : []).flatMap((g) => (Array.isArray(g?.campos) ? g.campos : []));
}

/**
 * Do que o servidor já gravou (`salvos`: [{ id, nome, valor, valor_id, unidade }]) para o estado dos campos.
 * Em campo de lista o que se guarda é o id da opção (`valor_id`); nos demais, o valor.
 */
export function valoresIniciais(salvos) {
    const out = {};
    (Array.isArray(salvos) ? salvos : []).forEach((s) => {
        if (! s || s.id == null) return;
        const id = String(s.id);
        const doIdDaOpcao = s.valor_id !== null && s.valor_id !== undefined && s.valor_id !== '';
        const bruto = doIdDaOpcao ? s.valor_id : s.valor;
        out[id] = { valor: bruto === null || bruto === undefined ? '' : String(bruto), unidade: s.unidade ? String(s.unidade) : '' };
    });

    return out;
}

/** Texto de um campo numérico na tela: o servidor guarda com ponto, quem digita usa vírgula. */
export const numeroParaTela = (v) => String(v ?? '').replace('.', ',');

/**
 * Corpo do PUT: um item para cada campo DA DEFINIÇÃO que a pessoa preencheu.
 * Campo vazio fica de fora (o servidor entende como "sem valor"); valor de um campo que a
 * categoria atual não tem também fica de fora. Número com unidade leva a unidade escolhida
 * ou, sem escolha, a padrão. Os demais tipos vão sem unidade.
 */
export function montarAtributos(grupos, valores) {
    const out = [];
    camposDaDefinicao(grupos).forEach((campo) => {
        const atual = valores?.[campo.id];
        const bruto = atual?.valor;
        const valor = bruto === null || bruto === undefined ? '' : String(bruto).trim();
        if (valor === '') return;
        const item = { id: campo.id, valor };
        if (campo.tipo === 'numero_unidade') {
            item.unidade = atual.unidade || campo.unidade_padrao || null;
        }
        out.push(item);
    });

    return out;
}

/**
 * Decide se vale a pena gravar a ficha técnica agora.
 * - Sem a definição dos campos carregada não se grava: mandar lista vazia apagaria o que já estava salvo.
 * - Nada preenchido e nada salvo antes: não há o que gravar (produto sem ficha continua sem ficha).
 * - Havia salvo e agora está tudo vazio: grava a lista vazia (é o "limpar").
 */
export function deveGravar({ definicaoPronta, atributos, jaTinhaSalvos }) {
    if (! definicaoPronta) return false;

    return atributos.length > 0 || jaTinhaSalvos;
}

/**
 * Erros do servidor (422) → { campos: { [id]: mensagem }, geral: mensagem | null }.
 * `atributos.<id>` vira a mensagem do campo; `atributos` (ou qualquer outra chave) é geral.
 */
export function errosDaResposta(e) {
    const campos = {};
    let geral = null;
    const erros = e?.response?.data?.errors;
    if (e?.response?.status === 422 && erros && typeof erros === 'object') {
        Object.entries(erros).forEach(([chave, msgs]) => {
            const mensagem = Array.isArray(msgs) ? String(msgs[0] ?? '') : String(msgs ?? '');
            if (mensagem === '') return;
            if (chave.startsWith('atributos.')) {
                const resto = chave.slice('atributos.'.length);
                // `atributos.0.valor` (formato da lista) não é de um campo: é erro geral.
                if (/^\d+(\.|$)/.test(resto)) geral = geral ?? mensagem;
                else campos[resto] = mensagem;
            } else {
                geral = geral ?? mensagem;
            }
        });
    }
    if (Object.keys(campos).length === 0 && geral === null) {
        geral = e?.response?.status === 419
            ? 'Sua sessão expirou. Recarregue a página.'
            : 'Não foi possível salvar a ficha técnica agora. Tente de novo.';
    }

    return { campos, geral };
}

/** O que aparece como id do elemento do campo na tela (para levar o foco ao primeiro erro). */
export const idDoElemento = (id) => `ficha-tec-${String(id).replace(/[^A-Za-z0-9_-]/g, '_')}`;

/** O id da categoria como o servidor aceita (letras e números), ou null quando não há categoria válida. */
export function categoriaParaConsulta(valor) {
    const t = String(valor ?? '').trim();

    return /^[A-Za-z]{2,5}\d{1,15}$/.test(t) ? t.toUpperCase() : null;
}
