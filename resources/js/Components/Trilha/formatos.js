// ─── Trilha de produtos — os textos e números que a tela escreve ────────────
//
// Formatação PURA (sem React e sem classe do Tailwind — este arquivo é `.js`, e o
// Tailwind só varre `.jsx`). Nenhuma regra de etapa mora aqui: a etapa de cada
// item e o status das ofertas chegam prontos do servidor
// (`App\Services\Trilha\TrilhaDaEmpresa`). Daqui sai só o texto, como o
// "Faltam 2: descrição, fotos (1 de 3)" da linha de um produto da lista.

// O servidor manda as horas em ISO 8601; a equipe e o cliente leem no horário de Brasília.
const FUSO = 'America/Sao_Paulo';

/** O mínimo de fotos por cor da regra da lista (o mesmo `FOTOS_MINIMAS` do serviço da trilha). */
export const FOTOS_MINIMAS = 3;

/**
 * Quantos itens a planilha da Lista confere, para a barrinha de "Situação". É a
 * conta do protótipo (cada falta tira um nono): as faltas reais variam de produto
 * para produto (a ficha técnica soma um campo por obrigatório), então a barra é
 * só uma noção visual — o número escrito ao lado é o que vale.
 */
const ITENS_CONFERIDOS_NA_LISTA = 9;

export const fmtBRL = (n) => (n == null || Number.isNaN(Number(n))
    ? '—'
    : Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));

/** "1 oferta" / "3 ofertas". */
export const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

/** O custo do produto: um valor só, ou "R$ 290,00 a R$ 310,00" quando as cores custam diferente; null sem custo. */
export function fmtCusto(produto) {
    const min = produto?.custo_min ?? null;
    const max = produto?.custo_max ?? null;
    if (min == null && max == null) return null;
    if (min == null || max == null || Number(min) === Number(max)) return fmtBRL(min ?? max);

    return `${fmtBRL(min)} a ${fmtBRL(max)}`;
}

/** "30/10 às 18:00" no fuso de São Paulo; null se a data não vier ou vier quebrada. */
export function fmtGeradoEm(iso) {
    if (!iso) return null;
    const data = new Date(iso);
    if (Number.isNaN(data.getTime())) return null;
    const dia = data.toLocaleDateString('pt-BR', { timeZone: FUSO, day: '2-digit', month: '2-digit' });
    const hora = data.toLocaleTimeString('pt-BR', { timeZone: FUSO, hour: '2-digit', minute: '2-digit' });

    return `${dia} às ${hora}`;
}

/** O SKU que a linha de um produto mostra: o código do grupo, ou o da 1ª cor. */
export const skuDoProduto = (produto) => produto?.codigo || produto?.skus?.[0] || null;

/** "1× CJ-210 + 6× CJ-211" — a composição de uma oferta pelos SKUs dos componentes. */
export const composicao = (oferta) => (oferta?.componentes ?? []).map((c) => `${c.quantidade}× ${c.sku}`).join(' + ');

/** "combo" → "Combo": a fase de uma sugestão do Planejamento, como rótulo. */
export const rotuloDaFase = (fase) => (fase ? fase.charAt(0).toUpperCase() + fase.slice(1) : '');

/**
 * A situação de uma linha da trilha (a coluna de status).
 *
 * - Oferta: o `status` que o servidor já escreveu, com o `alerta` dele.
 * - Produto na lista: o que falta, até 3 itens (o resto fica no painel lateral).
 * - Produto no mapeamento: quantas sugestões do Planejamento ainda esperam decisão
 *   (é o que segura o produto nesta etapa — ver a seção 5 do contrato da trilha).
 *   Com a trilha de trabalho (`trabalho`), quem segura é o "Concluir mapeamento"
 *   da própria trilha, não as sugestões: a linha diz só "falta mapear".
 *
 * @param {{tipo: 'p'|'o', item: object}} entrada  o formato de `itensDaEtapa()`
 * @param {{trabalho?: boolean}} [opcoes]
 * @returns {{texto: string, alerta: boolean}}
 */
export function situacaoDoItem({ tipo, item }, { trabalho = false } = {}) {
    if (tipo === 'o') return { texto: item.status ?? '', alerta: !!item.alerta };

    const faltas = item.faltas ?? [];
    if (faltas.length) {
        const primeiras = faltas.slice(0, 3).join(', ');
        const mais = faltas.length > 3 ? '…' : '';

        return { texto: `${faltas.length === 1 ? 'Falta' : 'Faltam'} ${faltas.length}: ${primeiras}${mais}`, alerta: true };
    }
    if (item.etapa === 'lista') return { texto: 'Lista incompleta', alerta: true };
    if (item.etapa === 'mapa') {
        const total = trabalho ? 0 : item.sugestoes_pendentes_total ?? 0;

        return {
            texto: total
                ? `Lista completa · ${plural(total, 'sugestão esperando decisão', 'sugestões esperando decisão')}`
                : 'Lista completa · falta mapear',
            alerta: false,
        };
    }

    return { texto: 'Mapeado · segue pelas ofertas', alerta: false };
}

/**
 * A coluna "Situação" da planilha da Lista: texto e barrinha.
 * @returns {{texto: string, porcento: number, tom: 'warn'|'ok'}}
 */
export function situacaoNaPlanilha(produto) {
    const n = produto?.faltas?.length ?? 0;
    if (n) {
        const feitos = ITENS_CONFERIDOS_NA_LISTA - Math.min(ITENS_CONFERIDOS_NA_LISTA, n);

        return { texto: `${n} falta${n > 1 ? 'm' : ''}`, porcento: Math.round((feitos / ITENS_CONFERIDOS_NA_LISTA) * 100), tom: 'warn' };
    }
    if (produto?.etapa === 'lista') return { texto: 'Incompleto', porcento: 0, tom: 'warn' };

    // Completo e ainda no mapeamento × já mapeado (segue pelas ofertas): os dois rótulos do protótipo.
    return { texto: produto?.etapa === 'mapa' ? 'Completo' : 'Já virou oferta', porcento: 100, tom: 'ok' };
}
