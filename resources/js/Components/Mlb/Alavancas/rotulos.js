// ─── Alavancas: rótulos que a equipe lê ─────────────────────────────────────
//
// ESM puro (sem JSX) para o `node --test` importar. As chaves espelham as
// constantes do PHP (TiposDePromocao::TODOS, PubAlavancaEscrita::RESULTADOS e
// ALAVANCAS) — o teste de gate confere o espelho.

export const ROTULO_TIPO = {
    DEAL: 'Campanha tradicional',
    MARKETPLACE_CAMPAIGN: 'Cofinanciada pelo ML',
    VOLUME: 'Leve mais, pague menos',
    DOD: 'Oferta do dia',
    LIGHTNING: 'Oferta relâmpago',
    PRICE_DISCOUNT: 'Desconto individual',
    PRE_NEGOTIATED: 'Desconto pré-acordado',
    SELLER_CAMPAIGN: 'Campanha do vendedor',
    SMART: 'Cofinanciada automatizada',
    PRICE_MATCHING: 'Preço competitivo',
    UNHEALTHY_STOCK: 'Liquidação de estoque Full',
    SELLER_COUPON_CAMPAIGN: 'Cupom do vendedor',
};

export const ROTULO_STATUS_PROMOCAO = {
    candidate: 'Candidato',
    pending: 'Programado',
    started: 'Ativo',
    finished: 'Encerrado',
};

// Espelho de `TiposDePromocao::CONVITES_DO_ML` e `PanoramaService::STATUS_ENCERRADOS` (o teste confere).
export const CONVITES_DO_ML = [
    'DEAL', 'MARKETPLACE_CAMPAIGN', 'DOD', 'LIGHTNING', 'VOLUME', 'PRE_NEGOTIATED', 'SMART', 'PRICE_MATCHING', 'UNHEALTHY_STOCK',
];
export const STATUS_ENCERRADOS = ['finished', 'closed', 'cancelled', 'deleted'];

/**
 * Convite aberto do Mercado Livre — mesmo critério do `PanoramaService::convitesAbertos`: tipo de convite do ML
 * e não vencido (com `dias_para_vencer`, só vale >= 0; sem ele, o status não pode ser de encerrada).
 */
export function ehConviteAberto(promocao) {
    if (! promocao || ! CONVITES_DO_ML.includes(promocao.tipo)) return false;
    const dias = promocao.dias_para_vencer ?? null;

    return dias !== null ? dias >= 0 : ! STATUS_ENCERRADOS.includes(promocao.status);
}

export const ROTULO_RESULTADO = {
    PENDENTE: 'Enviando',
    OK: 'Feito',
    ERRO: 'Recusado pelo Mercado Livre',
    INCERTO: 'Sem confirmação',
    RECUSADA: 'Não enviado',
};

export const ROTULO_ALAVANCA = {
    promocao: 'Promoções',
    cupom: 'Cupons',
    atacado: 'Atacado',
    exclusao: 'Campanhas automáticas',
};

export const ROTULO_ACAO = {
    'convite.inscrever': 'Inscrever na promoção',
    'convite.alterar': 'Alterar preço na promoção',
    'convite.remover': 'Tirar da promoção',
    'convite.remover_todas': 'Tirar de todas as promoções',
    'desconto.criar': 'Criar desconto individual',
    'desconto.remover': 'Remover desconto individual',
    'campanha.criar': 'Criar campanha',
    'campanha.alterar': 'Alterar campanha',
    'campanha.excluir': 'Excluir campanha',
    'exclusao.conta': 'Campanhas automáticas da conta',
    'exclusao.item': 'Campanhas automáticas do produto',
    'cupom.criar': 'Criar cupom',
    'cupom.alterar': 'Alterar cupom',
    'cupom.excluir': 'Excluir cupom',
    'atacado.gravar': 'Gravar faixas de atacado',
};

export const ROTULO_REPUTACAO = {
    '5_green': 'Verde',
    '4_light_green': 'Verde-clara',
    '3_yellow': 'Amarela',
    '2_orange': 'Laranja',
    '1_red': 'Vermelha',
};
