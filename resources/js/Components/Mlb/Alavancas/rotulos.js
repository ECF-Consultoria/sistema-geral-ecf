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
