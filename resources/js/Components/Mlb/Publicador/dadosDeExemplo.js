// ═══════════════════════════════════════════════════════════════════════════
// DADOS DE EXEMPLO do Publicador — NADA AQUI VEM DESTA CONTA.
//
// Quick 261010-t02b. A régua da tela 02 passou a ser: dado real quando ele
// existe, dado de EXEMPLO quando ainda não existe — nunca um quadro vazio. O
// que torna isso reversível é a disciplina de ter UM lugar só para o fictício:
// este arquivo.
//
// Quem abrir aqui entende em um segundo que nada é da conta do cliente. E o
// dia em que todas as integrações existirem, apagar este arquivo mostra
// exatamente o que ainda era mentira — cada bloco que quebrar é um bloco que
// precisava de dado real.
//
// REGRAS (há gate em `tests/js/publicador-dashboard-v2.test.js`):
//   1. Só constantes. Nenhuma função, nenhuma arrow, nenhuma lógica.
//   2. Nenhum import, nenhum require — o arquivo não depende de nada.
//   3. Todo bloco da tela que consome algo daqui carrega a pilha `SeloExemplo`.
//
// Cada constante sai da tela no dia em que o bloco correspondente passar a ler
// dado de verdade; o comentário de cada uma diz qual é esse dia.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Reputação/categoria da conta no Mercado Livre.
 * SAI quando o acervo passar a guardar `seller_reputation` da conta.
 */
export const CONTA_EXEMPLO = {
    selo: 'Conta Líder Platinum',
    reputacao: 'Platinum 100%',
};

/**
 * Frescor do ERP. O NOME do ERP é real (vem de `integracoes.erp`); o "há 8 min"
 * é exemplo — não existe sync de ERP no sistema.
 * SAI quando houver integração de estoque/ERP com carimbo de sincronização.
 */
export const ERP_EXEMPLO = {
    frescor: 'Sincronizado há 8 min',
};

/**
 * Contagem de SKU do catálogo, usada SÓ quando a conta não tem nenhum produto
 * cadastrado no Publicador (aí não há o que somar).
 * SAI quando toda conta tiver catálogo próprio no Publicador.
 */
export const CATALOGO_EXEMPLO = {
    sku_ativo: '1.420',
};

/**
 * Os três recortes de período do mockup. O seletor é VISUAL: marca o escolhido
 * e não refiltra nada — a tela mostra sempre o acervo inteiro.
 * SAI quando o servidor aceitar uma janela de período nesta tela.
 */
export const PERIODOS_EXEMPLO = {
    opcoes: ['Hoje', 'Últimos 7 dias', 'Este mês'],
    escolhido: 'Últimos 7 dias',
};

/**
 * Os dois alertas do mockup que não têm origem nenhuma no sistema: atributo
 * obrigatório pendente (o acervo não guarda atributo faltante por anúncio) e
 * estoque baixo no ERP (não lemos ERP).
 * SAI quando o acervo guardar atributos obrigatórios e quando houver ERP.
 */
export const ALERTAS_ML_EXEMPLO = [
    {
        chave: 'atributo_obrigatorio',
        titulo: 'Atributo Obrigatório Pendente',
        detalhe: "#MLB-392019: 'Voltagem' não preenchido em Carregadores.",
        acao: 'Corrigir Atributo',
        critico: true,
    },
    {
        chave: 'estoque_baixo_erp',
        titulo: 'Estoque Baixo no Bling',
        detalhe: 'Mouse Pad XL e Suporte Headset (< 3 un).',
        acao: 'Pausar Anúncios',
        critico: false,
    },
];

// ⚠️ `ATIVIDADE_EXEMPLO` SAIU daqui em 10/10/2026 (quick 261010-hdr).
//
// Eram os dois eventos do feed do mockup — "Gerou 5 imagens IA" e "revisão
// aprovada" — e eles NÃO deviam ter nascido de exemplo: a geração de criativo
// já estava gravada em `ml_anuncio_criativo_kits` (`user_id`, `created_at`) e
// a conferência em `pub_validacoes` desde a Fase 161/165. O usuário apontou
// isso vendo a tela em produção. Hoje a "Atividade da equipe" é a linha do
// tempo real das três fontes (`PainelVisaoGeralService::atividadeDaEquipe`),
// e este arquivo não tem mais nada de atividade. NÃO recriar a constante.

/**
 * A "Alavanca Recomendada" do mockup. O NÚMERO de anúncios dormentes é real
 * (os sem venda registrada); o diagnóstico e o botão são exemplo — não existe
 * reotimização por IA no Publicador.
 * SAI quando houver motor de recomendação sobre o acervo.
 */
export const TRACAO_EXEMPLO = {
    eyebrow: 'Alavanca Recomendada',
    prefixo: 'Otimizar',
    sufixo: 'anúncios dormentes',
    sem_numero: 'Otimizar os anúncios dormentes',
    detalhe: '42 anúncios têm títulos curtos (< 55 carac.) ou apenas 1 foto sem variações.',
    acao: 'Reotimizar com IA',
};

/**
 * Sparkline de ritmo de conversão diária.
 *
 * ⚠️ O CAMINHO REAL JÁ EXISTE NO BANCO: a série diária por conta mora em
 * `ml_acervo_metricas_diarias` — ninguém a lê nesta tela ainda. Quando o
 * servidor passar a mandar os últimos 14 dias nos `indicadores`, a troca é de
 * uma linha no painel (usar a série do servidor em vez desta constante) e esta
 * constante sai daqui junto com a pilha de exemplo do bloco.
 */
export const CONVERSAO_EXEMPLO = {
    titulo: 'Ritmo de conversão diária (últimos 14 dias)',
    media: 'Média 38 pedidos/dia',
    pontos: [22, 26, 24, 31, 29, 36, 34, 42, 38, 44, 40, 48, 50, 54],
};
