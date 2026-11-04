// ─── Trilha de produtos — a prévia do anúncio (4ª entrega, fatia 4a) ────────
//
// As contas PURAS da gaveta `v:{item}` (`Components/Trilha/Revisao/PreviaDoAnuncio.jsx`):
// a URL da prévia, os anúncios agrupados por tipo e cor, a foto de capa, os
// rótulos, o preço com as parcelas, o "ajustado" e o texto de quando não há
// prévia (o 409 do item fora da revisão, ou o 200 com `pronta: false`).
//
// O contrato é o §19.5 do desenho (`.planning/trilha/ENTREGA-3-DESENHO.md`), de
// `App\Services\Trilha\PreviaNaTrilha`: um anúncio por tipo × cor, as fotos com
// `gerada` (as que o robô fez), o preço por tipo com o da Precificação, o preparo
// e os bloqueios (só na porta da equipe).
//
// Esta lib mora no pedaço que SÓ a equipe baixa (o `lazy` da gaveta): a página do
// cliente não a importa — `trilhaEscrita.js` recebe a URL pronta. Mesmo assim os
// textos daqui não citam o marketplace, o Portal, o Publicador nem o "agente"
// (D59): o mesmo cartão vai ao cliente na 4e.
//
// Sem React, sem axios e sem classe do Tailwind (arquivo `.js`: o Tailwind só
// varre `.jsx`) — os tons das pílulas são nomes (`ok`, `warn`…), não classes.

/** O marcador que o servidor põe no lugar do id do item em `escrita.revisao.previa` (§19.4). */
export const MARCADOR_DO_ITEM = '__ITEM__';

/** O selo das fotos que o robô fez (cor própria na galeria). */
export const ROTULO_DA_GERADA = 'gerada pelo robô';

/** Sem prévia ainda (sem rascunho, ou o servidor não mandou motivo): o texto neutro do §19.5. */
export const MOTIVO_SEM_PREVIA = 'O robô ainda está preparando este anúncio.';

/** O 409 sem mensagem própria: o item saiu da revisão depois que a trilha carregou. */
export const MOTIVO_FORA_DA_REVISAO = 'Este anúncio não está mais na revisão. Recarregue a página para ver onde ele está.';

/** A oferta antes da revisão (D44): a gaveta nem pergunta ao servidor. */
export const ANTES_DA_REVISAO = 'O robô ainda prepara este anúncio: a prévia aparece quando ele chegar à revisão.';

/** O nome do tipo quando o servidor não manda a lista `tipos` (dado antigo). */
const NOME_DO_TIPO = { classico: 'Clássico', premium: 'Premium' };

/** A ordem dos campos do preparo do robô na Situação. */
const CAMPOS_DO_PREPARO = [
    ['titulo', 'Título'],
    ['descricao', 'Descrição'],
    ['imagens', 'Imagens'],
];

/** O estado de cada campo do preparo: o servidor manda `ok`; os outros são os do painel da oferta. */
const ESTADO_DO_PREPARO = {
    ok: { tom: 'ok', estado: 'pronto' },
    pronto: { tom: 'ok', estado: 'pronto' },
    fazendo: { tom: 'run', estado: 'fazendo' },
    fila: { tom: 'neutro', estado: 'na fila' },
    erro: { tom: 'bad', estado: 'erro' },
    pulado: { tom: 'neutro', estado: 'pulado' },
};

const numero = (v) => (v === null || v === undefined || v === '' || !Number.isFinite(Number(v)) ? null : Number(v));
const textoLimpo = (v) => (typeof v === 'string' && v.trim() ? v.trim() : null);
const lista = (v) => (Array.isArray(v) ? v : []);

// ─── A URL ───────────────────────────────────────────────────────────────────

/**
 * A URL da prévia de um item: o `escrita.revisao.previa` com o id no lugar do marcador
 * (`/trilha/459/ofertas/__ITEM__/previa` → `/trilha/459/ofertas/s-12/previa`). Null sem o endereço, sem o
 * marcador ou sem o id — a gaveta mostra "não disponível" em vez de pedir uma URL errada.
 */
export function urlDaPrevia(escrita, itemId) {
    const modelo = escrita?.revisao?.previa;
    if (typeof modelo !== 'string' || !modelo.includes(MARCADOR_DO_ITEM)) return null;
    if (itemId === null || itemId === undefined || String(itemId).trim() === '') return null;

    return modelo.split(MARCADOR_DO_ITEM).join(encodeURIComponent(String(itemId)));
}

// ─── Pronta ou não ───────────────────────────────────────────────────────────

/** A prévia tem anúncio para desenhar? (`pronta: true` e ao menos um anúncio.) */
export const previaPronta = (previa) => previa?.pronta === true && lista(previa?.anuncios).length > 0;

/** O porquê de não haver prévia: o `motivo` do servidor (já neutro, D42) ou o texto padrão. */
export const motivoDaPrevia = (previa) => textoLimpo(previa?.motivo) ?? MOTIVO_SEM_PREVIA;

// O idioma do Laravel aqui é o inglês: a mensagem padrão do framework não vai para a tela.
const DO_FRAMEWORK = /^(?:conflict|not found|forbidden|server error|too many (?:attempts|requests))\.?$/i;

/**
 * O 409 do servidor (o item fora da revisão, §19.4) vira uma prévia "não pronta" com a mensagem dele — é um estado,
 * não uma falha: a gaveta não oferece "Tentar de novo". Qualquer outro erro → null (a gaveta usa o texto comum).
 *
 * @returns {?{pronta: false, motivo: string, anuncios: [], conflito: true}}
 */
export function previaDoConflito(erro) {
    const resposta = erro?.response;
    if (resposta?.status !== 409) return null;
    const mensagem = textoLimpo(resposta.data?.message) ?? textoLimpo(resposta.data?.motivo);

    return {
        pronta: false,
        motivo: mensagem && !DO_FRAMEWORK.test(mensagem) ? mensagem : MOTIVO_FORA_DA_REVISAO,
        anuncios: [],
        conflito: true,
    };
}

/** A etapa que a gaveta mostra em "Onde está": a da prévia (mais nova) ou a da oferta. */
export const etapaDaPrevia = (previa, oferta) => previa?.item?.etapa ?? oferta?.etapa ?? null;

/** O status da Situação: o da prévia (mais novo) ou o da oferta. */
export const statusDaPrevia = (previa, oferta) => textoLimpo(previa?.item?.status) ?? textoLimpo(oferta?.status) ?? '—';

// ─── Tipos, cores e o anúncio escolhido ─────────────────────────────────────

/** A chave do tipo do anúncio (`classico`/`premium`, §19.5). */
const tipoDo = (anuncio) => (anuncio?.tipo == null ? null : String(anuncio.tipo));

/** A chave da cor do anúncio: `"v1"` (§19.5) — ou `{chave}`, a forma do retrato de origem. */
const varianteDo = (anuncio) => {
    const v = anuncio?.variante;
    if (v === null || v === undefined) return null;

    return typeof v === 'object' ? (v.chave == null ? null : String(v.chave)) : String(v);
};

/**
 * Os anúncios agrupados por tipo e cor: `{classico: {v1: anúncio, v2: anúncio}, premium: {…}}`. O primeiro de cada
 * par vale (o servidor não repete o par).
 */
export function agruparAnuncios(previa) {
    const grupos = {};
    for (const anuncio of lista(previa?.anuncios)) {
        const tipo = tipoDo(anuncio);
        const variante = varianteDo(anuncio);
        if (tipo === null || variante === null) continue;
        grupos[tipo] ??= {};
        grupos[tipo][variante] ??= anuncio;
    }

    return grupos;
}

/**
 * As abas de tipo (Clássico/Premium): só os tipos que têm anúncio, na ordem de `tipos`; um tipo que só aparece nos
 * anúncios entra no fim, com o nome conhecido.
 *
 * @returns {Array<{chave: string, nome: string}>}
 */
export function tiposDaPrevia(previa) {
    const grupos = agruparAnuncios(previa);
    const saida = lista(previa?.tipos)
        .filter((t) => t?.chave != null && grupos[String(t.chave)])
        .map((t) => ({ chave: String(t.chave), nome: textoLimpo(t.nome) ?? NOME_DO_TIPO[t.chave] ?? String(t.chave) }));
    for (const chave of Object.keys(grupos)) {
        if (!saida.some((t) => t.chave === chave)) saida.push({ chave, nome: NOME_DO_TIPO[chave] ?? chave });
    }

    return saida;
}

/**
 * As abas de cor: as cores que têm anúncio (no tipo, quando ele vem), na ordem de `variantes`; a que só aparece nos
 * anúncios entra no fim, com o rótulo que o anúncio trouxer.
 *
 * @returns {Array<{chave: string, rotulo: string}>}
 */
export function variantesDaPrevia(previa, tipo = null) {
    const grupos = agruparAnuncios(previa);
    const tem = (chave) => (tipo ? !!grupos[tipo]?.[chave] : Object.values(grupos).some((g) => !!g[chave]));
    const saida = lista(previa?.variantes)
        .filter((v) => v?.chave != null && tem(String(v.chave)))
        .map((v) => ({ chave: String(v.chave), rotulo: textoLimpo(v.rotulo) ?? String(v.chave) }));
    for (const anuncio of lista(previa?.anuncios)) {
        const chave = varianteDo(anuncio);
        if (chave === null || !tem(chave) || saida.some((v) => v.chave === chave)) continue;
        saida.push({ chave, rotulo: textoLimpo(anuncio?.variante?.rotulo) ?? chave });
    }

    return saida;
}

/** A escolha com que a gaveta abre: o 1º tipo (o Clássico, na ordem do servidor) e a 1ª cor dele. */
export function escolhaInicial(previa) {
    const tipo = tiposDaPrevia(previa)[0]?.chave ?? null;

    return { tipo, variante: variantesDaPrevia(previa, tipo)[0]?.chave ?? null };
}

/**
 * Uma escolha que existe: o tipo pedido (ou o 1º) e a cor pedida, se aquele tipo a tem (senão a 1ª dele). Trocar de
 * Clássico para Premium mantém a cor.
 */
export function ajustarEscolha(previa, escolha = {}) {
    const tipos = tiposDaPrevia(previa);
    const tipo = tipos.some((t) => t.chave === escolha?.tipo) ? escolha.tipo : tipos[0]?.chave ?? null;
    const variantes = variantesDaPrevia(previa, tipo);
    const variante = variantes.some((v) => v.chave === escolha?.variante) ? escolha.variante : variantes[0]?.chave ?? null;

    return { tipo, variante };
}

/** O anúncio da escolha; sem o par exato, o 1º do tipo; sem o tipo, o 1º da prévia. Null sem anúncio. */
export function anuncioEscolhido(previa, escolha = {}) {
    const grupos = agruparAnuncios(previa);
    const doTipo = grupos[escolha?.tipo] ?? null;

    return doTipo?.[escolha?.variante] ?? Object.values(doTipo ?? {})[0] ?? lista(previa?.anuncios)[0] ?? null;
}

// ─── Fotos ──────────────────────────────────────────────────────────────────

/** As fotos do anúncio, na ordem do anúncio (a 1ª é a capa), com `gerada` sempre booleano. Sem URL, fora. */
export function fotosDoAnuncio(anuncio) {
    return lista(anuncio?.fotos)
        .filter((f) => textoLimpo(f?.url))
        .map((f, i) => ({
            id: String(f.id ?? `foto-${i}`),
            url: f.url.trim(),
            // R3 (04/11/2026): a grade das miniaturas e a bolinha da cor pedem a versão pequena; a grande só na foto em
            // destaque. Sem miniatura (a porta do cliente, a foto antiga), a própria foto.
            miniatura: textoLimpo(f.miniatura) ? f.miniatura.trim() : f.url.trim(),
            gerada: f.gerada === true,
        }));
}

/** A foto de capa: a 1ª do anúncio (no Premium ela pode ser outra que a do Clássico). Null sem foto. */
export const capaDoAnuncio = (anuncio) => fotosDoAnuncio(anuncio)[0] ?? null;

/** O texto alternativo de uma foto da galeria: "Foto 2 de 6, gerada pelo robô". */
export const rotuloDaFoto = (foto, indice, total) => `Foto ${indice + 1} de ${total}${foto?.gerada ? `, ${ROTULO_DA_GERADA}` : ''}`;

/** Quantas das fotos o robô fez, em texto; null quando nenhuma. */
export function textoDasGeradas(fotos) {
    const total = lista(fotos).length;
    const geradas = lista(fotos).filter((f) => f?.gerada).length;
    if (!geradas) return null;
    if (geradas === total) return total === 1 ? 'A foto foi gerada pelo robô.' : `As ${total} fotos foram geradas pelo robô.`;

    return geradas === 1 ? `1 das ${total} fotos foi gerada pelo robô.` : `${geradas} das ${total} fotos foram geradas pelo robô.`;
}

// ─── Preço ──────────────────────────────────────────────────────────────────

/** "R$ 899,90"; "—" sem número. */
export const fmtPreco = (valor) => (numero(valor) === null
    ? '—'
    : numero(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));

/**
 * As parcelas sem juros do anúncio (só o Premium as tem; o Clássico vem com `null`): `{vezes, valor}`. Aceita a forma
 * direta e a do retrato de origem (`{sem_promocao: {vezes, valor}, com_promocao}` — a prévia mostra o preço cheio).
 */
export function parcelasDoAnuncio(anuncio) {
    const bruto = anuncio?.parcelas;
    if (!bruto || typeof bruto !== 'object') return null;
    const p = 'vezes' in bruto ? bruto : bruto.sem_promocao;
    const vezes = numero(p?.vezes);
    const valor = numero(p?.valor);

    return vezes !== null && vezes >= 1 && valor !== null ? { vezes, valor } : null;
}

/** "em 10× R$ 98,99 sem juros"; null sem parcelas. */
export const textoDasParcelas = (parcelas) => (parcelas ? `em ${parcelas.vezes}× ${fmtPreco(parcelas.valor)} sem juros` : null);

/** A promoção que o sistema tenta depois de publicar (R6 do desenho), em texto; null sem ela. */
export function textoDaPromocao(anuncio) {
    const preco = numero(anuncio?.promocao?.preco);
    if (preco === null) return null;
    const percentual = numero(anuncio.promocao.percentual);

    return `Depois de publicar, o sistema tenta uma promoção: ${fmtPreco(preco)}${percentual !== null ? ` (${Math.floor(percentual)}% de desconto)` : ''}.`;
}

/**
 * As linhas da caixa "Preço" (só leitura na 4a): uma por tipo, na ordem das abas, com o valor do anúncio e o da
 * Precificação. `ajustado` é o do servidor; sem ele, a diferença de meio centavo para cima (a régua do D46).
 *
 * @returns {Array<{chave: string, nome: string, valor: ?number, daPrecificacao: ?number, ajustado: boolean}>}
 */
export function linhasDoPreco(previa) {
    const precos = previa?.preco && typeof previa.preco === 'object' ? previa.preco : {};
    const nomes = new Map([...lista(previa?.tipos), ...tiposDaPrevia(previa)].filter((t) => t?.chave != null).map((t) => [String(t.chave), t.nome]));
    // Só os tipos (objetos): a 4c acrescenta ao `preco` as bandeiras `por_cor` e `premium_igual`, que não são linha.
    const ordem = [...new Set([...nomes.keys(), ...Object.keys(precos)])].filter((chave) => precos[chave] && typeof precos[chave] === 'object');

    return ordem.map((chave) => {
        const p = precos[chave];
        const valor = numero(p?.valor);
        const daPrecificacao = numero(p?.da_precificacao);
        const ajustado = typeof p?.ajustado === 'boolean'
            ? p.ajustado
            : valor !== null && daPrecificacao !== null && Math.abs(valor - daPrecificacao) >= 0.005;

        return { chave, nome: textoLimpo(nomes.get(chave)) ?? NOME_DO_TIPO[chave] ?? chave, valor, daPrecificacao, ajustado };
    });
}

/**
 * A nota embaixo do valor: "na Precificação: R$ 879,90" quando foi ajustado, "igual à Precificação" quando não, e
 * nada quando o valor da Precificação não vem (a porta do cliente, 4e).
 */
export function textoDoAjustado(linha) {
    if (linha?.daPrecificacao == null) return null;

    return linha.ajustado ? `na Precificação: ${fmtPreco(linha.daPrecificacao)}` : 'igual à Precificação';
}

// ─── O resto do cartão ──────────────────────────────────────────────────────

/** "Casa, Móveis e Decoração › Sala › Racks"; null sem categoria. */
export function caminhoDaCategoria(previa) {
    const partes = lista(previa?.categoria?.caminho).map(textoLimpo).filter(Boolean);

    return partes.length ? partes.join(' › ') : null;
}

/** A descrição do anúncio (uma só para todos), sem espaço sobrando; null sem descrição. */
export const descricaoDaPrevia = (previa) => textoLimpo(previa?.descricao);

/** As características agrupadas como o anúncio mostra; grupo vazio e item sem nome ou valor saem. */
export function gruposDaFicha(anuncio) {
    return lista(anuncio?.caracteristicas)
        .map((g) => ({
            grupo: textoLimpo(g?.grupo) ?? 'Características',
            itens: lista(g?.itens)
                .map((i) => ({ nome: textoLimpo(i?.nome), valor: textoLimpo(i?.valor == null ? null : String(i.valor)) }))
                .filter((i) => i.nome && i.valor),
        }))
        .filter((g) => g.itens.length > 0);
}

const ENTREGA = {
    me2: 'Entrega pelo ME2',
    me1: 'Entrega por transportadora (ME1)',
    not_specified: 'Entrega a combinar com o comprador',
    custom: 'Entrega com frete próprio',
};

/**
 * Como o anúncio entrega, em uma linha (com o aviso do servidor, quando há); null sem envio conhecido. Frete grátis é
 * o COMPRADOR não pagar a entrega (quem paga é o vendedor): vale em qualquer modo, e o texto diz isso.
 */
export function textoDoEnvio(envio) {
    const modo = ENTREGA[envio?.modo] ?? null;
    const aviso = textoLimpo(envio?.aviso);
    if (!modo && !aviso) return null;
    const entrega = modo ? `${modo}${envio?.frete_gratis === true ? ', com frete grátis para o comprador' : ''}.` : null;

    return [entrega, aviso].filter(Boolean).join(' ');
}

/**
 * O preparo do robô na Situação (só na porta da equipe): título, descrição e imagens, com o tom da pílula. Campo que
 * não veio fica de fora; sem `preparo`, nada.
 *
 * @returns {Array<{campo: string, rotulo: string, tom: string, estado: string}>}
 */
export function preparoDaPrevia(previa) {
    const preparo = previa?.preparo;
    if (!preparo || typeof preparo !== 'object') return [];

    return CAMPOS_DO_PREPARO
        .filter(([campo]) => preparo[campo] != null)
        .map(([campo, rotulo]) => ({ campo, rotulo, ...(ESTADO_DO_PREPARO[preparo[campo]] ?? { tom: 'neutro', estado: String(preparo[campo]) }) }));
}

/** Os bloqueios que o servidor manda (texto, ou objeto com a mensagem), como texto. */
export function bloqueiosDaPrevia(previa) {
    return lista(previa?.bloqueios)
        .map((b) => (typeof b === 'string' ? textoLimpo(b) : textoLimpo(b?.mensagem) ?? textoLimpo(b?.texto) ?? textoLimpo(b?.message) ?? textoLimpo(b?.titulo)))
        .filter(Boolean);
}
