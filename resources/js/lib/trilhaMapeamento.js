// ─── Trilha de produtos — o painel de mapear (2ª entrega, 02/11/2026) ───────
//
// O "Mapear" do produto, dentro da trilha: o estrategista marca os combos (o
// mesmo produto em mais unidades) e os outros produtos que combinam com ele
// (kit ou combit), confere em "Vai ser criado" e conclui. Quem calcula o que
// nasce — cor por cor, com o SKU do sistema e o "já existe" — é o SERVIDOR
// (`App\Services\Trilha\MapeamentoNaTrilha`): daqui sai só a ESCOLHA, no corpo
// `{combos: [2, 3], parceiros: [{produto_id, quantidade}], juntar}`, e o texto.
//
// Nada vem pré-marcado: a sugestão do sistema só dá a ordem e a etiqueta. A
// exceção é o combo que já existe em todas as cores — marcado e travado, para
// a prévia mostrar "já existe" em vez de deixar a pessoa achar que falta.
//
// As siglas são as do sistema (`KT-…`, `CT{n}-…`, `{SKU}-CB{n}`), nunca as do
// protótipo (`SKU1+SKU2-KIT`, `-CBT4`).
//
// Funções PURAS (sem React e sem classe do Tailwind). Testes:
// tests/js/trilha-escrita.test.js.

/** Quantas unidades o seletor do parceiro oferece (o mesmo `MAX_QUANTIDADE_PARCEIRO` do servidor). */
export const QUANTIDADES_DO_PARCEIRO = [1, 2, 3, 4, 5, 6];

/** Quantos produtos cabem numa oferta quando o servidor não diz (`ChaveDeComposicao::MAXIMO_COMPONENTES`). */
const MAXIMO_COMPONENTES = 6;

const comparar = (a, b) => String(a ?? '').localeCompare(String(b ?? ''), 'pt-BR', { numeric: true, sensitivity: 'base' });

/** Quantos produtos cabem numa oferta (o próprio produto conta como um). */
export const maximoDeComponentes = (opcoes) => Number(opcoes?.max_componentes) || MAXIMO_COMPONENTES;

/**
 * Quantos outros produtos dá para marcar de uma vez: os que cabem numa oferta
 * com este (é também o limite do pedido — o servidor recusa mais).
 */
export const maximoDeParceiros = (opcoes) => Math.max(1, maximoDeComponentes(opcoes) - 1);

/**
 * Os outros produtos na ordem do painel: os que casam primeiro; entre eles, os
 * que o sistema sugere com este, depois os de ambiente em comum, depois pelo
 * nome. O servidor já manda nessa ordem; a tela não depende disso.
 */
export function ordenarParceiros(parceiros) {
    return [...(parceiros ?? [])].sort((a, b) => (
        (a.casa === false) - (b.casa === false)
        || (a.sugerido == null) - (b.sugerido == null)
        || (!a.ambiente) - (!b.ambiente)
        || comparar(a.nome, b.nome)
        || Number(a.produto_id) - Number(b.produto_id)
    ));
}

/**
 * O começo das escolhas: nada marcado, fora o combo que já existe em todas as
 * cores (marcado e travado).
 *
 * @returns {{combos: number[], marcados: Object<string, boolean>, quantidades: Object<string, number>, juntar: boolean}}
 */
export function escolhasIniciais(opcoes) {
    return {
        combos: (opcoes?.combos ?? []).filter((c) => c.existe === 'todas').map((c) => Number(c.quantidade)),
        marcados: {},
        quantidades: {},
        juntar: false,
    };
}

/** O combo é travado quando já existe em todas as cores. */
export const comboTravado = (combo) => combo?.existe === 'todas';

/** Marca ou desmarca um combo (o travado não sai). */
export function alternarCombo(escolhas, opcoes, quantidade, ligado) {
    const n = Number(quantidade);
    const combo = (opcoes?.combos ?? []).find((c) => Number(c.quantidade) === n);
    if (comboTravado(combo)) return escolhas;
    const sem = escolhas.combos.filter((q) => q !== n);

    return { ...escolhas, combos: ligado ? [...sem, n] : sem };
}

/** Quantos outros produtos estão marcados. */
export const quantosMarcados = (escolhas) => Object.values(escolhas?.marcados ?? {}).filter(Boolean).length;

/** A quantidade escolhida para um parceiro (1 se ninguém mexeu no seletor). */
export const quantidadeDe = (escolhas, produtoId) => Number(escolhas?.quantidades?.[produtoId]) || 1;

/** "Juntar todos" só vale com 2 ou mais marcados, e cabendo numa oferta só. */
export const podeJuntar = (marcados, opcoes) => marcados >= 2 && marcados <= maximoDeParceiros(opcoes);

/**
 * Marca ou desmarca outro produto. Não passa do limite de parceiros; com menos
 * de 2 marcados, o "Juntar todos" desliga sozinho.
 */
export function alternarParceiro(escolhas, opcoes, produtoId, ligado) {
    if (ligado && !escolhas.marcados[produtoId] && quantosMarcados(escolhas) >= maximoDeParceiros(opcoes)) return escolhas;
    const marcados = { ...escolhas.marcados, [produtoId]: !!ligado };
    if (!ligado) delete marcados[produtoId];

    return { ...escolhas, marcados, juntar: escolhas.juntar && podeJuntar(Object.keys(marcados).length, opcoes) };
}

/** Mudar a quantidade de um parceiro já o marca (como no protótipo), se ainda couber. */
export function mudarQuantidade(escolhas, opcoes, produtoId, quantidade) {
    const q = QUANTIDADES_DO_PARCEIRO.includes(Number(quantidade)) ? Number(quantidade) : 1;
    const comQuantidade = { ...escolhas, quantidades: { ...escolhas.quantidades, [produtoId]: q } };

    return escolhas.marcados[produtoId] ? comQuantidade : alternarParceiro(comQuantidade, opcoes, produtoId, true);
}

/** Liga ou desliga o "Juntar todos numa oferta só" (só liga quando pode). */
export function alternarJuntar(escolhas, opcoes, ligado) {
    return { ...escolhas, juntar: !!ligado && podeJuntar(quantosMarcados(escolhas), opcoes) };
}

/**
 * O corpo da prévia e do "Concluir": combos em ordem, os parceiros marcados na
 * ordem do painel (só os que casam) e o "juntar" só quando vale.
 *
 * @returns {{combos: number[], parceiros: Array<{produto_id: number, quantidade: number}>, juntar: boolean}}
 */
export function corpoDoMapeamento(escolhas, opcoes) {
    const combos = [...new Set((escolhas?.combos ?? []).map(Number))].filter((n) => Number.isInteger(n) && n >= 2).sort((a, b) => a - b);
    const parceiros = ordenarParceiros(opcoes?.parceiros)
        .filter((p) => p.casa !== false && escolhas?.marcados?.[p.produto_id])
        .slice(0, maximoDeParceiros(opcoes))
        .map((p) => ({ produto_id: p.produto_id, quantidade: quantidadeDe(escolhas, p.produto_id) }));

    return { combos, parceiros, juntar: !!escolhas?.juntar && podeJuntar(parceiros.length, opcoes) };
}

/** O estado de cada linha de "Vai ser criado", como pílula. O porquê vai no `detalhe`, embaixo. */
const ESTADO_DA_LINHA = {
    sempre: { rotulo: 'sempre', tom: 'neutro' },
    nova: { rotulo: 'nova', tom: 'ok' },
    nova_parcial: { rotulo: 'nova em parte', tom: 'ok' },
    ja_existe: { rotulo: 'já existe', tom: 'neutro' },
    nao_da: { rotulo: 'não dá', tom: 'bad' },
};

/** @returns {{rotulo: string, tom: string}} */
export const estadoDaLinha = (linha) => ESTADO_DA_LINHA[linha?.estado] ?? { rotulo: String(linha?.estado ?? ''), tom: 'neutro' };

/** "1 oferta" / "3 ofertas" — a pílula de "Vai ser criado", que conta só as novas. */
export const ofertasNovas = (n) => `${n} ${n === 1 ? 'oferta' : 'ofertas'}`;

/**
 * O texto do "Mapeamento concluído" quando o servidor não manda o dele — o mesmo do servidor
 * (`MapeamentoNaTrilha::concluir`). Não diz onde as ofertas ficaram: o item de um combo que já existia
 * em outras cores pode estar numa onda. Quem diz é o status de cada uma, na lista "Criadas".
 */
export function textoDasCriadas(n) {
    if (!n) return 'Mapeamento concluído: nenhuma oferta nova.';

    return n === 1 ? '1 oferta criada.' : `${n} ofertas criadas.`;
}

/**
 * As sugestões do sistema que o "Concluir" descartou. Sem citar o Portal: com a trilha de trabalho (a única que
 * mapeia aqui) a trilha substitui o Portal do cliente (3ª entrega, F5) — "Mapear de novo" é o caminho de volta.
 */
export function textoDasDescartadas(n) {
    if (!n) return null;

    return n === 1
        ? '1 sugestão do sistema que não foi escolhida foi descartada.'
        : `${n} sugestões do sistema que não foram escolhidas foram descartadas.`;
}

/** O próximo produto esperando o mapeamento (o "Continuar mapeando"); null se não sobrou nenhum. */
export const proximoParaMapear = (dados, produtoId) => (dados?.produtos ?? []).find((p) => p.etapa === 'mapa' && p.id !== produtoId) ?? null;

/** "Mapeado em 02/11 por Rafael" (o cliente não recebe o nome: "Mapeado em 02/11"); null se nunca foi mapeado na trilha. */
export function textoDoMapeamento(mapeadoEm, mapeadoPor) {
    if (!mapeadoEm) return null;
    const data = new Date(mapeadoEm);
    if (Number.isNaN(data.getTime())) return null;
    const dia = data.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit' });

    return mapeadoPor ? `Mapeado em ${dia} por ${mapeadoPor}` : `Mapeado em ${dia}`;
}
