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
// Valores em memória: { [id]: { valor: string, unidade: string, naoSeAplica?: boolean, outro?: boolean } }
//
// "Não se aplica": o campo marcado `nao_se_aplica` pelo servidor ganha a opção.
// Marcada, o PUT manda `{ id, nao_se_aplica: true }` (nunca um valor), e o servidor
// a grava com `valor_id = NAO_SE_APLICA`. O valor digitado antes fica guardado em
// memória: desmarcar o devolve.
// ═══════════════════════════════════════════════════════════════════════

/** Como o servidor grava o "Não se aplica" (`valor_id`). */
export const NAO_SE_APLICA = '-1';

/** O campo oferece "Não se aplica"? (só quando o servidor marcou). */
export const aceitaNaoSeAplica = (campo) => !! campo?.nao_se_aplica;

/** Os campos da definição numa lista só, na ordem em que a tela os mostra. */
export function camposDaDefinicao(grupos) {
    return (Array.isArray(grupos) ? grupos : []).flatMap((g) => (Array.isArray(g?.campos) ? g.campos : []));
}

// ─── Eixo de variação por PRODUTO, não por categoria ────────────────────────
//
// O campo que É um eixo de variação (`eixo_do_portal`: 'cor', 'material'…) vem na
// definição da categoria, e quem decide se ele aparece é o PRODUTO: some só quando
// alguma variação usa aquele eixo (o valor vem da variação). A cadeira que varia por
// cor informa o material aqui; a que varia por material, não. É a mesma regra do
// servidor (`FichaTecnicaDaCategoria::doProduto`), que ignora o campo ao gravar.

/**
 * As chaves dos eixos usados nas variações (`eixo_rotulo`: "Cor", "Material"…), a partir do
 * vocabulário do servidor ({ cor: 'Cor', … }). Aceita a chave direto também. Vazio não conta.
 */
export function eixosEmUso(variacoes, eixosDoVocabulario) {
    const porRotulo = new Map();
    Object.entries(eixosDoVocabulario ?? {}).forEach(([chave, rotulo]) => {
        porRotulo.set(String(rotulo).trim().toLowerCase(), chave);
        porRotulo.set(String(chave).trim().toLowerCase(), chave);
    });
    const usados = [];
    (Array.isArray(variacoes) ? variacoes : []).forEach((v) => {
        const texto = String(v?.eixo_rotulo ?? v?.eixo ?? '').trim().toLowerCase();
        const chave = texto === '' ? null : porRotulo.get(texto);
        if (chave && ! usados.includes(chave)) usados.push(chave);
    });

    return usados;
}

/** A definição como vale para o produto: sem os campos que são eixo de alguma variação dele. Grupo vazio sai. */
export function gruposDoProduto(grupos, eixos) {
    const usados = new Set(Array.isArray(eixos) ? eixos : []);

    return (Array.isArray(grupos) ? grupos : [])
        .map((g) => ({ ...g, campos: (Array.isArray(g?.campos) ? g.campos : []).filter((c) => ! (c?.eixo_do_portal && usados.has(c.eixo_do_portal))) }))
        .filter((g) => g.campos.length > 0);
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
        if (String(s.valor_id ?? '') === NAO_SE_APLICA) {
            out[id] = { valor: '', unidade: '', naoSeAplica: true };

            return;
        }
        const doIdDaOpcao = s.valor_id !== null && s.valor_id !== undefined && s.valor_id !== '';
        const bruto = doIdDaOpcao ? s.valor_id : s.valor;
        out[id] = { valor: bruto === null || bruto === undefined ? '' : String(bruto), unidade: s.unidade ? String(s.unidade) : '' };
    });

    return out;
}

/** Texto de um campo numérico na tela: o servidor guarda com ponto, quem digita usa vírgula. */
export const numeroParaTela = (v) => String(v ?? '').replace('.', ',');

/** Como o servidor emenda os nomes de uma lista multivalor numa linha só. */
export const SEPARADOR_MULTIVALOR = ' | ';

/**
 * O id da opção escolhida num campo de lista, ou '' quando nada bate.
 *
 * Casa por id E POR NOME, e é o nome que importa: campo que HOJE tem opções pode
 * ter sido de texto livre antes, e o que ficou gravado nele é o NOME, sem
 * `valor_id`. Casar só por id mostraria "Selecione" num campo correto — e salvar
 * assim APAGARIA o valor. Valor que não bate em opção nenhuma devolve '' de
 * propósito: é dado que não serve, e a pessoa precisa reescolher.
 */
export function idDeLista(campo, bruto) {
    const t = String(bruto ?? '').trim();
    if (t === '') return '';
    const opcoes = Array.isArray(campo?.valores) ? campo.valores : [];

    const exato = opcoes.find((o) => String(o.id) === t || String(o.nome) === t);
    if (exato) return String(exato.id);

    const solto = t.toLowerCase();
    const porCaixa = opcoes.find((o) => String(o.nome).toLowerCase() === solto);

    return porCaixa ? String(porCaixa.id) : '';
}

/**
 * Os ids das opções escolhidas num campo multivalor, na ordem, sem repetição.
 *
 * O que está guardado em memória vem de dois lugares e esta função aceita os dois:
 * uma LISTA de ids (o que os chips produzem ao editar) ou o TEXTO que o servidor
 * devolveu em `salvos` — os nomes emendados, porque a linha gravada não tem os ids
 * (ver `FichaTecnicaDoProduto::multivalor`). Cada pedaço passa por {@link idDeLista},
 * então vale o mesmo casamento por nome; o que não bate em opção nenhuma é descartado.
 */
export function idsMultivalor(campo, bruto) {
    const pedacos = Array.isArray(bruto)
        ? bruto
        : String(bruto ?? '').split(SEPARADOR_MULTIVALOR);

    const out = [];
    pedacos.forEach((p) => {
        const id = idDeLista(campo, p);
        if (id !== '' && ! out.includes(id)) out.push(id);
    });

    return out;
}

/** O campo aceita mais de uma opção? (só lista, e só quando o servidor marcou). */
export const ehMultivalor = (campo) => campo?.tipo === 'lista' && !! campo?.multivalor;

// ─── "Outro": o valor escrito à mão num campo de lista (11/10/2026) ─────────
//
// Algumas listas aceitam um valor próprio além das opções (o servidor marca `aceita_outro`): o
// material que não está entre as opções, por exemplo. A lista continua vindo primeiro; "Outro" é
// uma escolha explícita. Em memória o valor próprio é o TEXTO (as opções são guardadas pelo id), e
// `outro: true` marca o gesto de ter escolhido "Outro" enquanto o texto ainda está vazio.

/** O campo de lista aceita um valor escrito à mão além das opções? (só quando o servidor marcou). */
export const aceitaOutro = (campo) => campo?.tipo === 'lista' && !! campo?.aceita_outro;

/** O que o seletor usa como valor da opção "Outro" (nenhuma opção de verdade tem este id). */
export const OPCAO_OUTRO = '__outro__';

/**
 * O valor próprio de uma lista de escolha única: o texto, ou null quando o campo está numa opção
 * (ou vazio, sem "Outro" escolhido). Texto gravado que não bate em opção nenhuma É o valor próprio —
 * inclusive o que foi escrito quando o campo ainda era de texto livre.
 */
export function valorProprio(campo, atual) {
    if (! aceitaOutro(campo) || ehMultivalor(campo) || Array.isArray(atual?.valor)) return null;
    const texto = String(atual?.valor ?? '');
    if (atual?.outro) return texto;

    return texto.trim() !== '' && idDeLista(campo, texto) === '' ? texto : null;
}

/**
 * O que está escolhido num campo multivalor, na ordem e sem repetição: o id de cada opção e, onde o
 * campo aceita, o texto de cada valor próprio. Sem `aceita_outro` é o mesmo que {@link idsMultivalor}.
 */
export function valoresMultivalor(campo, bruto) {
    const pedacos = Array.isArray(bruto) ? bruto : String(bruto ?? '').split(SEPARADOR_MULTIVALOR);
    const proprios = aceitaOutro(campo);
    const out = [];
    const vistos = new Set();
    pedacos.forEach((p) => {
        const id = idDeLista(campo, p);
        const valor = id !== '' ? id : (proprios ? String(p ?? '').trim().replace(/\s+/g, ' ') : '');
        const chave = id !== '' ? `id:${id}` : `texto:${valor.toLowerCase()}`;
        if (valor === '' || vistos.has(chave)) return;
        vistos.add(chave);
        out.push(valor);
    });

    return out;
}

/** Dos escolhidos de um multivalor, os que são valor próprio (texto), e não id de opção. */
export function propriosDoMultivalor(campo, escolhidos) {
    const ids = new Set((Array.isArray(campo?.valores) ? campo.valores : []).map((o) => String(o.id)));

    return (Array.isArray(escolhidos) ? escolhidos : []).map(String).filter((v) => ! ids.has(v));
}

/** O valor próprio pode ser somado aos escolhidos? Vazio, repetido ou com "|" (o separador) não pode. */
export function podeSomarProprio(campo, escolhidos, texto) {
    const limpo = String(texto ?? '').trim().replace(/\s+/g, ' ');
    if (limpo === '' || limpo.includes('|')) return false;

    return valoresMultivalor(campo, [...(Array.isArray(escolhidos) ? escolhidos : []), limpo]).length > (escolhidos?.length ?? 0);
}

/**
 * Corpo do PUT: um item para cada campo DA DEFINIÇÃO que a pessoa preencheu.
 * Campo vazio fica de fora (o servidor entende como "sem valor"); valor de um campo que a
 * categoria atual não tem também fica de fora. Número com unidade leva a unidade escolhida
 * ou, sem escolha, a padrão. Os demais tipos vão sem unidade. Campo multivalor manda a
 * LISTA de ids escolhidos — nenhum escolhido fica de fora, como qualquer campo vazio.
 */
export function montarAtributos(grupos, valores) {
    const out = [];
    camposDaDefinicao(grupos).forEach((campo) => {
        const atual = valores?.[campo.id];
        const bruto = atual?.valor;

        // "Não se aplica" vence o que estiver digitado, e só vale onde o servidor oferece.
        if (atual?.naoSeAplica && aceitaNaoSeAplica(campo)) {
            out.push({ id: campo.id, nao_se_aplica: true });

            return;
        }

        if (ehMultivalor(campo)) {
            // Ids das opções e, onde o campo aceita, o texto de cada valor próprio — estes vão
            // também em `proprios`, para o servidor nunca ler texto como id (nem id velho como texto).
            const escolhidos = valoresMultivalor(campo, bruto);
            const proprios = propriosDoMultivalor(campo, escolhidos);
            if (escolhidos.length) out.push({ id: campo.id, valor: escolhidos, ...(proprios.length ? { proprios } : {}) });

            return;
        }

        // "Outro": o texto escrito à mão vai com `proprio`, para o servidor nunca o ler como id de opção.
        const proprio = valorProprio(campo, atual);
        if (proprio !== null) {
            const texto = proprio.trim();
            if (texto !== '') out.push({ id: campo.id, valor: texto, proprio: true });

            return;
        }

        // Lista de escolha única manda o ID da opção. Valor que não bate em nenhuma (texto
        // livre antigo, num campo que NÃO aceita valor próprio) fica de fora: é o mesmo que vazio,
        // e a tela também o mostra vazio. Obrigatório assim volta como "Preencha …", que é
        // o pedido certo — mandar o texto cru só daria 422 travando a ficha inteira.
        if (campo.tipo === 'lista') {
            const id = idDeLista(campo, bruto);
            if (id !== '') out.push({ id: campo.id, valor: id });

            return;
        }

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
