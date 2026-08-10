<?php

/*
|--------------------------------------------------------------------------
| Parâmetros da Fase 134 — "Meus Anúncios" (acervo publicado no ML)
|--------------------------------------------------------------------------
|
| Único lugar da fase onde vivem N da rotação da camada cara (D-23), o
| tamanho do lote de detalhe, os limites reais confirmados contra a API do
| Mercado Livre, a retenção da série diária (D-07) e o limiar de defasagem
| (D-08). Nenhum consumidor deve hardcodar esses números — sempre ler daqui.
|
| Fonte dos números: `.planning/phases/134-meus-anuncios-saude-analitica-do-anuncio-publicado/134-RESEARCH.md`
| §3/§4 (custo/volume) e `134-CONTEXT.md` D-07/D-08/D-21/D-23 (decisões).
|
*/

return [

    // D-23 — cobertura completa da camada cara (visitas + price_to_win) em N
    // dias. Conta do 134-RESEARCH.md §A-03/§3: 406.932 itens ativos medidos
    // em produção + ~150-180 mil chamadas de price_to_win (itens
    // catalog_listing=true) somam ~587 mil chamadas/dia se fosse cobertura
    // total diária (~6,5h a 25 req/s). Com N=7 cai para ~84 mil/dia
    // (~56 min), na mesma ordem de grandeza da janela do ml:sync.
    'rotacao_n' => (int) env('MLB_ACERVO_ROTACAO_N', 7),

    // Tamanho do lote de itens por execução do job de detalhe da camada cara.
    // Mantém cada job em dezenas de segundos, muito abaixo do retry_after da
    // fila — ver Pitfall 1 do 134-RESEARCH.md (job monolítico por empresa
    // grande estourando o retry_after e duplicando execução).
    'chunk_detalhe' => (int) env('MLB_ACERVO_CHUNK_DETALHE', 500),

    // Multiget de itens (`GET /items?ids=`): 20 ids é o teto REAL da API,
    // confirmado por erro real da própria API ("only allows 20 elements") —
    // não é suposição de documentação.
    'lote_multiget' => 20,

    // Página do scroll de enumeração (`search_type=scan`). 50 mantém o
    // round-trip curto — o scroll_id tem TTL de 5 minutos e a enumeração
    // precisa rodar sem delay artificial entre páginas (134-RESEARCH.md §2).
    'pagina_scroll' => 50,

    // D-07 — retenção da série diária enxuta (visitas, vendas, saúde).
    'retencao_dias' => (int) env('MLB_ACERVO_RETENCAO_DIAS', 90),

    // D-08 — a partir de quantas horas sem coleta a tela mostra o banner de
    // defasagem ("coletado há N dias").
    'defasagem_horas' => (int) env('MLB_ACERVO_DEFASAGEM_HORAS', 24),

    // D-21 — veredicto da sondagem deste plano (134-01, Task 2). false =
    // Variante B do 134-UI-SPEC.md (só Nota ECF, com tooltip explicando que
    // o ML não expõe mais um score comparável). Só vira true se a Task 2
    // provar que `GET /item/{id}/performance` responde para item clássico.
    //
    // SONDAGEM PENDENTE (tentada em 2026-08-10, não concluída). O banco
    // local não tem nenhuma empresa com MlToken ativo (134-RESEARCH.md §
    // Environment Availability), então a sondagem só produz resposta
    // contra produção. A Task 2 tentou abrir sessão SSH na VPS (plink,
    // reusando SSH_ARGS de deploy.sh) e foi BLOQUEADA pelo classificador
    // de permissões do ambiente de execução — não por indisponibilidade
    // de rede/credencial. Mantido em `false` (fallback honesto do D-21,
    // Variante B) até um humano rodar a sondagem manualmente. Comando
    // exato para repetir (direto na VPS, ou via SSH com permissão
    // concedida): `php artisan mlb:acervo-sondar --fixtures` em
    // `/var/www/ecf_admin`. Ver 134-01-SUMMARY.md para o relato completo.
    'saude_ml_disponivel' => (bool) env('MLB_ACERVO_SAUDE_ML', false),

];
