// ─── Prévia do anúncio (etapa 5 do editor, 20/10/2026) ──────────────────────
//
// ESM puro (sem JSX) para o `node --test` importar. O servidor
// (`PreviaDoAnuncioService`) manda um anúncio por tipo × variação, tirado do
// MESMO plano da publicação; a tela escolhe UM e o mostra como o comprador vê
// no Mercado Livre. Aqui moram as contas da tela: qual anúncio, que preço
// aparece, os centavos em cima e as notas para quem revisa.
//
// Nada inventado: a quantidade de parcelas do Premium vem do servidor (a faixa
// de preço em `publicador.previa.parcelas_premium`), nunca de número fixo aqui.

const numero = (v) => (v === null || v === undefined || v === '' || ! Number.isFinite(Number(v)) ? null : Number(v));

/** "1234.5" → { inteiro: '1.234', centavos: '50' } — no ML os centavos ficam pequenos, no alto. Nulo = sem preço. */
export function partesDoPreco(valor) {
    const n = numero(valor);
    if (n === null || n < 0) return null;
    const [inteiro, centavos] = n.toFixed(2).split('.');

    return { inteiro: inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.'), centavos };
}

/** "R$ 1.234,56" */
export function reais(valor) {
    const p = partesDoPreco(valor);

    return p === null ? '' : `R$ ${p.inteiro},${p.centavos}`;
}

/**
 * O anúncio que a tela mostra: o do tipo e da variação pedidos; sem o par exato, o
 * primeiro daquele tipo; sem o tipo, o primeiro do plano. Nulo = prévia sem anúncio.
 */
export function anuncioEscolhido(previa, tipo, varianteChave) {
    const lista = Array.isArray(previa?.anuncios) ? previa.anuncios : [];
    if (lista.length === 0) return null;
    const doTipo = lista.filter((a) => a?.listing_type_id === tipo);

    return doTipo.find((a) => a?.variante?.chave === varianteChave) ?? doTipo[0] ?? lista[0];
}

/**
 * O preço que aparece. Com a promoção (quando ela existe e a chave está ligada): o preço
 * cheio riscado, o da Central grande e o "% OFF". As parcelas acompanham o preço mostrado.
 *
 * @return {{ atual: ?number, anterior: ?number, off: ?number, parcelas: ?{vezes: number, valor: number} }}
 */
export function precoMostrado(anuncio, comPromocao) {
    const cheio = numero(anuncio?.preco);
    const promo = comPromocao && anuncio?.promocao && numero(anuncio.promocao.preco) !== null ? anuncio.promocao : null;
    const parcelas = anuncio?.parcelas ? (promo ? anuncio.parcelas.com_promocao : anuncio.parcelas.sem_promocao) : null;

    return {
        atual: promo ? numero(promo.preco) : cheio,
        anterior: promo ? cheio : null,
        // O ML mostra o desconto inteiro ("16% OFF" para 16,67%).
        off: promo && numero(promo.percentual) !== null ? Math.floor(numero(promo.percentual)) : null,
        parcelas: parcelas && numero(parcelas.vezes) !== null && numero(parcelas.valor) !== null ? { vezes: numero(parcelas.vezes), valor: numero(parcelas.valor) } : null,
    };
}

/** A 1ª foto do anúncio daquela variação no mesmo tipo — a miniatura do seletor de variação. */
export function capaDaVariante(previa, tipo, varianteChave) {
    const a = (previa?.anuncios ?? []).find((x) => x?.listing_type_id === tipo && x?.variante?.chave === varianteChave);

    return a?.fotos?.[0]?.url ?? null;
}

/**
 * O que quem revisa precisa saber e a página do ML não diz (fica fora do cartão branco).
 *
 * @return {string[]}
 */
export function notasDaPrevia(previa, anuncio, comPromocao) {
    if (! anuncio) return [];
    const notas = [];

    const valores = (anuncio.variante?.valores ?? []).map((v) => v?.valor).filter(Boolean);
    if (anuncio.titulo && valores.length > 0) {
        notas.push(`O Mercado Livre acrescenta «${valores.join(' ')}» ao fim do título.`);
    }

    const outro = (previa?.anuncios ?? []).find((x) => x?.variante?.chave === anuncio.variante?.chave && x?.listing_type_id !== anuncio.listing_type_id);
    const capa = anuncio.fotos?.[0]?.id ?? null;
    if (outro && capa !== null && (outro.fotos?.[0]?.id ?? null) !== null && outro.fotos[0].id !== capa) {
        notas.push(`A capa muda entre ${anuncio.tipo} e ${outro.tipo}: as fotos são as mesmas, em outra ordem.`);
    }

    const guardadas = (anuncio.fotos ?? []).filter((f) => f && f.no_ml === false).length;
    if (guardadas > 0) {
        notas.push(guardadas === 1
            ? '1 foto ainda não subiu ao Mercado Livre: aparece pela cópia guardada aqui e sobe na publicação.'
            : `${guardadas} fotos ainda não subiram ao Mercado Livre: aparecem pela cópia guardada aqui e sobem na publicação.`);
    }

    if (anuncio.promocao) {
        const dias = numero(previa?.dias_da_promocao);
        const quando = previa?.promocao_automatica
            ? `O sistema cria a promoção da Central sozinho depois de publicar${dias ? `, por ${dias} dias` : ''}.`
            : 'Nesta conta a promoção da Central não é criada sozinha: a tarefa pós-publicação orienta a criar no Seller Center.';
        notas.push(comPromocao ? quando : `${quando} Desligada aqui, a prévia mostra o preço cheio.`);
    } else if (anuncio.sem_promocao) {
        notas.push(`Sem promoção da Central: ${anuncio.sem_promocao}.`);
    }

    if (anuncio.parcelas) {
        notas.push('As parcelas sem juros do Premium são estimadas pela faixa de preço do Mercado Livre.');
    }

    return notas;
}
