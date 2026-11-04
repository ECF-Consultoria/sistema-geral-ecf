// ─── Trilha de produtos — o preço ajustável da prévia (4ª entrega, fatia 4c) ─
//
// As contas PURAS da caixa "Preço" editável da gaveta `v:{item}`
// (`Components/Trilha/Revisao/CaixaDoPreco.jsx`): a URL, ler o que se digita
// ("899,90" → 899.9), o estado da caixa e as mudanças dele, o que mudou em
// relação ao anúncio de agora, o corpo do `PUT`, o espelho da regra "Premium
// igual ao Clássico", os textos e o que fazer com cada erro (409/422).
//
// O contrato é o §19.11 do desenho (`.planning/trilha/ENTREGA-3-DESENHO.md`):
// `PUT {escrita.revisao.preco}` (com `__ITEM__` no lugar do id) com
// `{revisao, precos: {classico?, premium?}, cor}` — `null` num tipo = voltar ao
// da Precificação, tipo ausente = não mexe, `cor` null = todas as cores. O 200 é
// a prévia nova (§19.5) com `preco.{tipo}.{valor, da_precificacao, minimo,
// ajustado}`, `preco.por_cor`, `preco.premium_igual` e `avisos`. O 409 da
// revisão traz `revisao_atual` (sem `travado`); o da fila/publicado/fora da
// revisão, só `message`. O 422 vem em `errors["precos.classico"|"precos.premium"]`.
//
// Quem decide é o SERVIDOR (D46): o "Premium igual ao Clássico" daqui é só o
// aviso na hora, e o mínimo da Precificação é aviso, não trava.
//
// Mora no pedaço que SÓ a equipe baixa (o `lazy` da gaveta) e não importa a lib
// da prévia (`trilhaPrevia.js`): as libs `trilha*` são varridas como "de fora" da
// pasta `Revisao/` (tests/js/trilha-empresa-render.test.js). Por isso o marcador
// e o formato do real são cópias — o teste confere que são iguais aos de lá. O
// número digitado segue a regra única do sistema (`numeroBr.js`, a mesma do
// `NumeroBr::DINHEIRO` do servidor).
//
// Sem React, sem axios e sem classe do Tailwind (arquivo `.js`).

import { normalizarNumeroBr } from './numeroBr.js';

/** O marcador do id do item na URL (`escrita.revisao.preco`, §19.4) — o mesmo `MARCADOR_DO_ITEM` da prévia. */
export const MARCADOR_DO_ITEM = '__ITEM__';

/** A régua do D46: preços que diferem menos que isto são "iguais". */
export const MEIO_CENTAVO = 0.005;

/** O texto do servidor para o Premium igual ao Clássico (§19.11) — o aviso na hora usa o mesmo. */
export const MSG_PREMIUM_IGUAL = 'O Premium não pode ter o mesmo preço do Clássico.';

/** O aviso quando o anúncio de agora já está com o Premium igual ao Clássico (`preco.premium_igual`). */
export const AVISO_PREMIUM_IGUAL_AGORA = 'O Premium está com o mesmo preço do Clássico: ajuste um dos dois antes de publicar.';

/** O 409 da revisão sem mensagem própria. */
export const MSG_MUDOU = 'Este anúncio mudou depois que você abriu. Recarregue para ver o que mudou.';

/** O 409 da fila, do publicado ou de fora da revisão sem mensagem própria. */
export const MSG_SO_LEITURA = 'O preço deste anúncio não pode mais ser mudado daqui. Recarregue a página para ver onde ele está.';

/** O 422 sem mensagem que sirva. */
export const MSG_CONFIRA = 'Confira o preço e tente de novo.';

/** A nota depois de salvar. */
export const NOTA_SALVO = 'Preço salvo.';

/** A dica do rodapé da caixa quando nada foi mexido (D45: o preço é só do anúncio). */
export const DICA_DO_PRECO = 'O preço vale só para este anúncio: a Precificação não muda.';

/** O estado da caixa ao abrir (e depois de recarregar a prévia). */
export const ESTADO_DO_PRECO = Object.freeze({
    cor: null,
    digitado: Object.freeze({}),
    tentou: false,
    salvando: false,
    erros: Object.freeze({}),
    erroGeral: null,
    conflito: null,
    leitura: null,
    avisos: Object.freeze([]),
    nota: null,
});

const NOME_DO_TIPO = { classico: 'Clássico', premium: 'Premium' };

const numero = (v) => (v === null || v === undefined || v === '' || !Number.isFinite(Number(v)) ? null : Number(v));
const textoLimpo = (v) => (typeof v === 'string' && v.trim() ? v.trim() : null);
const lista = (v) => (Array.isArray(v) ? v : []);
const centavos = (v) => Math.round(v * 100);

/** "R$ 899,90" (com o espaço sem quebra do `toLocaleString`, como o `fmtPreco` da prévia); "—" sem número. */
export const reais = (valor) => (numero(valor) === null
    ? '—'
    : numero(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));

// ─── A URL ───────────────────────────────────────────────────────────────────

/**
 * A URL do preço de um item: o `escrita.revisao.preco` com o id no lugar do marcador
 * (`/trilha/459/ofertas/__ITEM__/preco` → `/trilha/459/ofertas/s-12/preco`). Null sem o endereço (quem não escreve,
 * o servidor de antes da 4c), sem o marcador ou sem o id — a caixa fica só leitura.
 */
export function urlDoPreco(escrita, itemId) {
    const modelo = escrita?.revisao?.preco;
    if (typeof modelo !== 'string' || !modelo.includes(MARCADOR_DO_ITEM)) return null;
    if (itemId === null || itemId === undefined || String(itemId).trim() === '') return null;

    return modelo.split(MARCADOR_DO_ITEM).join(encodeURIComponent(String(itemId)));
}

// ─── O que se digita ────────────────────────────────────────────────────────

/** O valor no jeito de digitar: 899.9 → "899,90" (sem ponto de milhar); sem número, vazio. */
export function textoDoPreco(valor) {
    const n = numero(valor);

    return n === null ? '' : n.toFixed(2).replace('.', ',');
}

/**
 * Lê o preço digitado: "899,90", "899.90", "1.299,90", "1.299", "R$ 899,9" → número com até 2 casas. O separador
 * segue a regra única do sistema (`normalizarNumeroBr`): com vírgula, ela é o decimal; sem vírgula, ponto seguido de
 * grupos de 3 dígitos é milhar.
 *
 * @returns {{valor: ?number, erro: ?string}}
 */
export function lerPreco(texto) {
    const limpo = String(texto ?? '').replace(/R\$/gi, '').replace(/[\s ]/g, '');
    if (!limpo) return { valor: null, erro: 'Informe o preço.' };
    const normal = normalizarNumeroBr(limpo);
    if (!/^(?=.*\d)\d*(?:\.\d*)?$/.test(normal)) return { valor: null, erro: 'Use só números, com vírgula nos centavos (ex.: 899,90).' };
    if ((normal.split('.')[1] ?? '').length > 2) return { valor: null, erro: 'Use no máximo 2 casas depois da vírgula.' };
    const valor = Number(normal);
    if (!(valor > 0)) return { valor: null, erro: 'O preço precisa ser maior que zero.' };

    return { valor: centavos(valor) / 100, erro: null };
}

// ─── Os anúncios, por tipo e cor ────────────────────────────────────────────

const tipoDo = (anuncio) => (anuncio?.tipo == null ? null : String(anuncio.tipo));

/** A chave da cor do anúncio (a canônica, `variantes[].chave`) — ou `{chave}`, a forma do retrato de origem. */
const varianteDo = (anuncio) => {
    const v = anuncio?.variante;
    if (v === null || v === undefined) return null;

    return typeof v === 'object' ? (v.chave == null ? null : String(v.chave)) : String(v);
};

/** O preço de cada cor de um tipo: `{v1: 899.9, v2: 949.9}` (o 1º anúncio de cada par vale, como na prévia). */
export function precosDasCores(previa, tipo) {
    const saida = {};
    for (const anuncio of lista(previa?.anuncios)) {
        const variante = varianteDo(anuncio);
        if (tipoDo(anuncio) !== tipo || variante === null || variante in saida) continue;
        saida[variante] = numero(anuncio.preco);
    }

    return saida;
}

/**
 * As cores que o seletor oferece ("Todas as cores" × uma cor): as que têm anúncio, na ordem de `variantes`; a que só
 * aparece nos anúncios entra no fim.
 *
 * @returns {Array<{chave: string, rotulo: string}>}
 */
export function coresDoPreco(previa) {
    const comAnuncio = new Map();
    for (const anuncio of lista(previa?.anuncios)) {
        const chave = varianteDo(anuncio);
        if (chave !== null && !comAnuncio.has(chave)) comAnuncio.set(chave, textoLimpo(anuncio?.variante?.rotulo) ?? chave);
    }
    const saida = lista(previa?.variantes)
        .filter((v) => v?.chave != null && comAnuncio.has(String(v.chave)))
        .map((v) => ({ chave: String(v.chave), rotulo: textoLimpo(v.rotulo) ?? String(v.chave) }));
    for (const [chave, rotulo] of comAnuncio) {
        if (!saida.some((v) => v.chave === chave)) saida.push({ chave, rotulo });
    }

    return saida;
}

// ─── Pode editar? ────────────────────────────────────────────────────────────

/**
 * A caixa é editável só para a equipe que recebeu `escrita.revisao.preco`, com a prévia pronta, o item NA REVISÃO
 * (a publicada é só conferência) e algum tipo com preço. A fila e o publicado o servidor responde com 409 — aí a
 * caixa vira só leitura pela resposta (`estado.leitura`).
 */
export function podeEditarPreco(escrita, previa, oferta = null) {
    if (!urlDoPreco(escrita, oferta?.id ?? previa?.item?.id)) return false;
    if (previa?.pronta !== true || previa?.conflito || !lista(previa?.anuncios).length) return false;
    if ((previa?.item?.etapa ?? oferta?.etapa) !== 'revisao') return false;

    return linhasDoPrecoEditavel(previa).length > 0;
}

// ─── As linhas da caixa ─────────────────────────────────────────────────────

/**
 * As linhas da caixa, uma por tipo ligado (o `preco` do tipo veio — o desligado vem `null`), na ordem das abas, para a
 * cor escolhida: o valor de agora (o da cor; com "Todas as cores", o `preco.{tipo}.valor`, que é o da 1ª cor ativa),
 * o da Precificação, o mínimo, o "ajustado", se as cores têm preços diferentes e a faixa deles.
 *
 * @returns {Array<{chave: string, nome: string, atual: ?number, daPrecificacao: ?number, minimo: ?number,
 *   ajustado: boolean, porCor: boolean, faixa: ?{de: number, ate: number}, semACor: boolean}>}
 */
export function linhasDoPrecoEditavel(previa, cor = null) {
    const precos = previa?.preco && typeof previa.preco === 'object' ? previa.preco : {};
    const nomes = new Map(lista(previa?.tipos).filter((t) => t?.chave != null).map((t) => [String(t.chave), textoLimpo(t.nome)]));
    // Só os tipos (objetos): `por_cor` e `premium_igual` são bandeiras do `preco`, não linha.
    const ordem = [...new Set([...nomes.keys(), ...Object.keys(precos)])].filter((chave) => precos[chave] && typeof precos[chave] === 'object');

    return ordem.map((chave) => {
        const p = precos[chave];
        const dasCores = precosDasCores(previa, chave);
        const valores = Object.values(dasCores).filter((v) => v !== null);
        const distintos = [...new Set(valores.map(centavos))];
        const temAnuncios = Object.keys(dasCores).length > 0;
        const semACor = cor !== null && temAnuncios && !(cor in dasCores);
        const valor = numero(p.valor);
        const daPrecificacao = numero(p.da_precificacao);
        const ajustado = typeof p.ajustado === 'boolean'
            ? p.ajustado
            : valor !== null && daPrecificacao !== null && Math.abs(valor - daPrecificacao) >= MEIO_CENTAVO;

        return {
            chave,
            nome: nomes.get(chave) ?? NOME_DO_TIPO[chave] ?? chave,
            atual: cor !== null && dasCores[cor] != null ? dasCores[cor] : valor,
            daPrecificacao,
            minimo: numero(p.minimo),
            ajustado,
            porCor: temAnuncios ? distintos.length > 1 : previa?.preco?.por_cor === true,
            faixa: distintos.length > 1 ? { de: Math.min(...distintos) / 100, ate: Math.max(...distintos) / 100 } : null,
            semACor,
        };
    });
}

// ─── As mudanças do estado (puras: o hook só guarda o resultado) ─────────────

/** Digitar num tipo: o texto fica, a volta à Precificação sai, o erro daquele campo e a nota do último salvar também. */
export function digitarPreco(estado, tipo, texto) {
    const erros = { ...estado.erros };
    delete erros[tipo];

    return { ...estado, digitado: { ...estado.digitado, [tipo]: { texto: String(texto ?? ''), voltar: false } }, erros, erroGeral: null, nota: null, avisos: [] };
}

/**
 * [Voltar ao da Precificação] de um tipo: o campo mostra o valor da Precificação e o pedido manda `null` (o servidor
 * volta a herdar). Clicar de novo ([Desfazer]) tira a volta: o campo mostra o de agora.
 */
export function alternarVolta(estado, linha) {
    const digitado = { ...estado.digitado };
    const erros = { ...estado.erros };
    delete erros[linha.chave];
    if (digitado[linha.chave]?.voltar) delete digitado[linha.chave];
    else digitado[linha.chave] = { texto: textoDoPreco(linha.daPrecificacao), voltar: true };

    return { ...estado, digitado, erros, erroGeral: null, nota: null, avisos: [] };
}

/** "Todas as cores" (null) × uma cor: o que foi digitado fica (vale para a escolha nova). */
export function escolherCor(estado, cor) {
    return { ...estado, cor: cor === '' || cor === undefined ? null : cor, erros: {}, erroGeral: null, nota: null };
}

// ─── O que mudou, o corpo e os avisos ───────────────────────────────────────

/**
 * Os preços EFETIVOS de cada cor depois do pedido (a régua do D46 olha cada cor): o que o pedido manda para a cor
 * (todas, ou só a escolhida), o da Precificação quando volta (o `da_precificacao` do tipo — a tela só conhece o da 1ª
 * cor) e, no resto, o preço de agora do anúncio.
 *
 * @returns {Record<string, Record<string, ?number>>}  `{classico: {v1: 899.9}, premium: {v1: 989.9}}`
 */
export function precosEfetivos(previa, precos = {}, cor = null) {
    const saida = {};
    for (const tipo of ['classico', 'premium']) {
        const dasCores = precosDasCores(previa, tipo);
        saida[tipo] = {};
        for (const [variante, atual] of Object.entries(dasCores)) {
            const mexe = Object.prototype.hasOwnProperty.call(precos, tipo) && (cor === null || cor === variante);
            if (!mexe) saida[tipo][variante] = atual;
            else if (precos[tipo] === null) saida[tipo][variante] = numero(previa?.preco?.[tipo]?.da_precificacao) ?? atual;
            else saida[tipo][variante] = numero(precos[tipo]);
        }
    }

    return saida;
}

/**
 * As cores em que o Premium fica com o mesmo preço do Clássico depois do pedido (o espelho do D46). Como no servidor, só
 * as cores que o pedido TOCA (todas, ou só a escolhida): o empate que já existia noutra não impede consertar esta.
 */
export function coresComPremiumIgual(previa, precos = {}, cor = null) {
    const efetivos = precosEfetivos(previa, precos, cor);

    return Object.keys(efetivos.premium).filter((v) => {
        if (cor !== null && v !== cor) return false;
        const c = efetivos.classico[v];
        const p = efetivos.premium[v];

        return c != null && p != null && Math.abs(c - p) < MEIO_CENTAVO;
    });
}

/**
 * Tudo o que a caixa desenha, a partir da prévia e do estado: as cores do seletor, a cor valendo, as linhas (com o texto
 * do campo, a mudança de cada tipo — `undefined` não mexe, `null` volta, número —, o erro e os avisos), o corpo do `PUT`
 * (só quando há mudança e nada inválido), se está mexido (para a guarda e o botão), o resumo e o aviso do Premium igual.
 */
export function vistaDoPreco(previa, estado = ESTADO_DO_PRECO) {
    const cores = coresDoPreco(previa);
    const cor = estado?.cor != null && cores.some((c) => c.chave === estado.cor) ? estado.cor : null;
    const digitado = estado?.digitado ?? {};
    const erros = estado?.erros ?? {};

    const linhas = linhasDoPrecoEditavel(previa, cor).map((l) => {
        const dig = digitado[l.chave] ?? null;
        const voltando = !!dig?.voltar && !l.semACor;
        const lido = dig && !dig.voltar && !l.semACor ? lerPreco(dig.texto) : null;
        let mudanca;
        if (voltando) mudanca = null;
        else if (lido && !lido.erro) {
            const diferente = l.atual === null || Math.abs(lido.valor - l.atual) >= MEIO_CENTAVO;
            // "Todas as cores" com as cores em preços diferentes: o mesmo valor da 1ª cor ainda iguala as outras.
            if (diferente || (cor === null && l.porCor)) mudanca = lido.valor;
        }
        const invalido = !!lido?.erro;
        const erro = textoLimpo(erros[l.chave]) ?? (invalido && estado?.tentou ? lido.erro : null);

        return {
            ...l,
            texto: dig ? dig.texto : textoDoPreco(l.atual),
            voltando,
            mudanca,
            invalido,
            erro,
            podeVoltar: l.ajustado && !l.semACor,
            abaixoDoMinimo: typeof mudanca === 'number' && l.minimo !== null && mudanca < l.minimo - MEIO_CENTAVO,
        };
    });

    const precos = {};
    for (const l of linhas) {
        if (l.mudanca !== undefined) precos[l.chave] = l.mudanca;
    }
    const temMudanca = Object.keys(precos).length > 0;
    const invalido = linhas.some((l) => l.invalido);
    const iguais = temMudanca ? coresComPremiumIgual(previa, precos, cor) : [];
    let premiumIgual = null;
    if (iguais.length) premiumIgual = textoDoPremiumIgual(iguais, cores);
    else if (!temMudanca ? previa?.preco?.premium_igual === true : cor !== null && coresComPremiumIgual(previa).some((v) => v !== cor)) {
        // Sem mudança, o que o servidor diz do anúncio de agora; com uma cor só, o empate que segue nas outras.
        premiumIgual = AVISO_PREMIUM_IGUAL_AGORA;
    }

    return {
        cores,
        cor,
        rotuloDaCor: cores.find((c) => c.chave === cor)?.rotulo ?? null,
        linhas,
        precos,
        temMudanca,
        invalido,
        mexido: temMudanca || invalido,
        corpo: temMudanca && !invalido ? corpoDoPreco(previa, precos, cor) : null,
        premiumIgual,
        resumo: temMudanca ? resumoDoPedido(linhas, cores, cor) : null,
    };
}

/** O corpo do `PUT` (§19.11): a `revisao` da prévia aberta, só os tipos que mudam e a cor (null = todas). */
export function corpoDoPreco(previa, precos, cor = null) {
    return { revisao: previa?.revisao ?? null, precos: { ...precos }, cor: cor ?? null };
}

/** "O Premium não pode ter o mesmo preço do Clássico." — com a cor, quando o anúncio tem mais de uma. */
export function textoDoPremiumIgual(chaves, cores = []) {
    if (cores.length <= 1 || !chaves.length) return MSG_PREMIUM_IGUAL;
    const rotulos = chaves.map((c) => cores.find((x) => x.chave === c)?.rotulo ?? c);
    if (rotulos.length === cores.length) return `${MSG_PREMIUM_IGUAL} Fica igual em todas as cores.`;

    return `${MSG_PREMIUM_IGUAL} Fica igual ${rotulos.length === 1 ? 'na cor' : 'nas cores'} ${juntar(rotulos)}.`;
}

const juntar = (itens) => (itens.length <= 1 ? itens.join('') : `${itens.slice(0, -1).join(', ')} e ${itens.at(-1)}`);

/**
 * O que o [Salvar preço] vai fazer, em uma linha: "Clássico: R$ 899,90 → R$ 949,90 · Premium: volta ao da
 * Precificação (R$ 989,90) · em todas as cores."
 */
export function resumoDoPedido(linhas, cores = [], cor = null) {
    const partes = linhas
        .filter((l) => l.mudanca !== undefined)
        .map((l) => {
            if (l.mudanca === null) return `${l.nome}: volta ao da Precificação${l.daPrecificacao !== null ? ` (${reais(l.daPrecificacao)})` : ''}`;
            if (cor === null && l.porCor) return `${l.nome}: ${reais(l.mudanca)}`;

            return `${l.nome}: ${reais(l.atual)} → ${reais(l.mudanca)}`;
        });
    if (!partes.length) return null;
    let escopo = '';
    if (cor !== null) escopo = `só na cor ${cores.find((c) => c.chave === cor)?.rotulo ?? cor}`;
    else if (cores.length > 1) escopo = 'em todas as cores';

    return `${[...partes, escopo].filter(Boolean).join(' · ')}.`;
}

/** "da Precificação: R$ 879,90 · mínimo R$ 700,00" — o que fica embaixo de cada campo. */
export function textoDaReferencia(linha) {
    const daPrecificacao = linha?.daPrecificacao != null ? `da Precificação: ${reais(linha.daPrecificacao)}` : 'sem preço na Precificação';

    return linha?.minimo != null ? `${daPrecificacao} · mínimo ${reais(linha.minimo)}` : daPrecificacao;
}

/** O aviso na hora do valor digitado abaixo do mínimo (o servidor devolve o mesmo em `avisos`; não trava). */
export const textoAbaixoDoMinimo = (linha) => `Abaixo do mínimo da Precificação (${reais(linha?.minimo)}).`;

/** As cores com preços diferentes, quando "Todas as cores" vai igualar: "de R$ 899,90 a R$ 949,90". */
export function textoDasCoresDiferentes(linha) {
    const faixa = linha?.faixa ? ` (de ${reais(linha.faixa.de)} a ${reais(linha.faixa.ate)})` : '';

    return `As cores estão com preços diferentes${faixa}: o que salvar aqui vale para todas.`;
}

/** O botão: "Salvar preço", ou "Salvando…" enquanto o pedido está no ar. */
export const textoDoBotaoDoPreco = (estado) => (estado?.salvando ? 'Salvando…' : 'Salvar preço');

/** Os avisos da resposta (`avisos`: o "abaixo do mínimo"), como texto. */
export function avisosDaResposta(resposta) {
    return lista(resposta?.avisos)
        .map((a) => (typeof a === 'string' ? textoLimpo(a) : textoLimpo(a?.mensagem) ?? textoLimpo(a?.texto) ?? textoLimpo(a?.message)))
        .filter(Boolean);
}

// ─── Os erros ───────────────────────────────────────────────────────────────

// O idioma do Laravel nesta instalação é o inglês: a mensagem padrão do framework (e a das regras sem texto próprio)
// não vai para a tela — a cópia da régua de `trilhaEscrita.js`, que esta lib não importa (puxa o axios).
const DO_FRAMEWORK = /^(?:conflict|not found|forbidden|server error|unauthenticated|page expired|too many (?:attempts|requests)|this action is unauthorized|the given data was invalid)\b|^the .+ (?:field|must|may|is)\b/i;

const doServidor = (m) => {
    const t = textoLimpo(m);

    return t && !DO_FRAMEWORK.test(t) ? t : null;
};

/** O tipo do campo pelo nome do erro do 422: `precos.classico` → `classico`. */
const CAMPO_DO_ERRO = /^precos\.(classico|premium)$/;

/**
 * O que um erro do `PUT` quer dizer para a caixa:
 * - `revisao` — 409 com `revisao_atual` (sem `travado`): o anúncio mudou depois que a gaveta abriu → [Recarregar];
 * - `leitura` — os outros 409 (na fila, publicando, publicado, fora da revisão): a caixa vira só leitura com a mensagem;
 * - `campos` — 422: a mensagem de cada tipo (`precos.classico`/`precos.premium`) e a geral (o resto);
 * - `outro` — o resto (rede, 403, 429, 500…): quem chama usa o texto comum (`mensagemDoErro`).
 *
 * @returns {{tipo: 'revisao'|'leitura'|'campos'|'outro', mensagem: ?string, campos: Record<string, string>, revisaoAtual: ?number}}
 */
export function erroDoPreco(erro) {
    const resposta = erro?.response;
    const data = resposta?.data && typeof resposta.data === 'object' ? resposta.data : {};
    if (resposta?.status === 409) {
        if (Object.prototype.hasOwnProperty.call(data, 'revisao_atual') && !data.travado) {
            return { tipo: 'revisao', mensagem: doServidor(data.message) ?? MSG_MUDOU, campos: {}, revisaoAtual: numero(data.revisao_atual) };
        }

        return { tipo: 'leitura', mensagem: doServidor(data.message) ?? doServidor(data.motivo) ?? MSG_SO_LEITURA, campos: {}, revisaoAtual: null };
    }
    if (resposta?.status === 422) {
        const campos = {};
        let geral = null;
        for (const [nome, mensagens] of Object.entries(data.errors ?? {})) {
            const primeira = [mensagens].flat().map(doServidor).find(Boolean) ?? null;
            const campo = nome.match(CAMPO_DO_ERRO)?.[1];
            if (campo) campos[campo] = primeira ?? 'Confira este preço.';
            else geral ??= primeira;
        }
        if (!Object.keys(campos).length) geral ??= doServidor(data.message) ?? MSG_CONFIRA;

        return { tipo: 'campos', mensagem: geral, campos, revisaoAtual: null };
    }

    return { tipo: 'outro', mensagem: null, campos: {}, revisaoAtual: null };
}

/**
 * O estado da caixa depois de um erro do `PUT`. `comum` é o texto do erro que a caixa não trata (o `mensagemDoErro` da
 * escrita, que esta lib não importa). O digitado fica — menos no 409 da fila/publicado, em que a caixa vira só leitura.
 */
export function estadoDoErro(estado, erro, comum = null) {
    const e = erroDoPreco(erro);
    const base = { ...estado, salvando: false, tentou: true, nota: null };
    if (e.tipo === 'revisao') return { ...base, conflito: e.mensagem, erroGeral: null };
    if (e.tipo === 'leitura') return { ...base, leitura: e.mensagem, digitado: {}, erros: {}, erroGeral: null, conflito: null };
    if (e.tipo === 'campos') return { ...base, erros: e.campos, erroGeral: e.mensagem };

    return { ...base, erroGeral: textoLimpo(comum) ?? 'Não deu certo. Tente de novo.' };
}

/** O estado da caixa depois de salvar: o digitado sai (a prévia nova já tem o preço), os avisos da resposta e a nota entram. */
export function estadoDepoisDeSalvar(estado, resposta) {
    return { ...ESTADO_DO_PRECO, cor: estado?.cor ?? null, avisos: avisosDaResposta(resposta), nota: NOTA_SALVO };
}
