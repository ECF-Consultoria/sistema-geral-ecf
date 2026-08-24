---
quick_id: 260824-bte
slug: pagamento-escalonado
date: 2026-08-24
status: complete
---

# Pagamento escalonado

## Resultado

A guarda `servicos_duplicados` (quick `260821-l8n`) deixou de tratar "mesmo
servico em mais de uma linha" como erro de cadastro. Agora ela consolida as
fases (quando a ordem e derivavel) num unico `ContratoAssinatura` por
`servico_id`, com o `servicos_snapshot` carregando as fases ordenadas e a
frase `{{plano_parcelas}}` composta automaticamente (ou usando um override
digitado na tela).

Caso vivo resolvido: **Mons Bike, `company_id=431`**, servico Gestao, 3
parcelas de R$ 5.500 + 9 de R$ 6.000 - antes bloqueado por
`servicos_duplicados`, agora gera UM contrato com a frase "As 3 (tres)
primeiras parcelas correspondera a R$ 5.500,00 e as 9 (nove) demais a
R$ 6.000,00.".

## O que mudou

### Tarefa 1 - ContratoClicksignService::iniciarParaEmpresa()

- O laco passou a agrupar ContratoServico ativos por servico_id (groupBy),
  em vez de iterar item a item.
- Grupos com mais de uma fase sao ordenados por
  hubspot_snapshot->line_item->hs_recurring_billing_start_date (vazio/nulo
  primeiro, depois por data crescente) via ordenarFasesOuNull().
- Quando a ordem NAO e derivavel (duas fases sem data de inicio, ou com a
  mesma data), o grupo inteiro cai em pulados com motivo
  servicos_duplicados - um pulado por item do grupo, preservando a
  granularidade que os testes de 260821-l8n ja esperavam - e um
  Log::warning e emitido, igual a antes.
- servicosDuplicados() (leitura pura consumida por
  ContratoAdminController::show()) foi generalizada da mesma forma: so
  aponta servicos cuja ordem e ambigua, nao mais qualquer duplicidade.
- Cada fase do snapshot ganhou a chave parcelas (de hubspot_billing_period,
  formato P<N>M -> N; null quando o periodo nao esta definido no HubSpot).
- O delay escalonado do GerarContratoAssinaturaJob passou a contar so os
  contratos EFETIVAMENTE criados, nao a posicao no laco original.
- Cobertura nova: fases com ordem derivavel (Mons Bike), ordem invertida na
  entrada, ultima fase sem periodo, e o caminho AUTOMATICO via
  ContratoServicoGatilhoObserver (dentro do mesmo DB::transaction() que
  HubspotWebhookController::persistirContratos() usa).

### Tarefa 2 - ContratoPdfService::somarValores()

- Passou a somar POR SERVICO (primeira ocorrencia do nome no snapshot), nao
  mais toda entrada - duas fases do mesmo servico nao dobram mais
  valor_mensal_formatado. Servicos DIFERENTES continuam somando normalmente
  (regressao zero, coberto pelo teste pre-existente de 3 servicos
  distintos).
- Compatibilidade com snapshot legado (uma fase so, sem a chave parcelas)
  preservada sem migracao de dado.

### Tarefa 3 - plano_parcelas composto e editavel

- 3a. Coluna plano_parcelas_texto (nullable) em contrato_assinaturas
  (migration 2026_08_24_100000), no fillable de ContratoAssinatura.
- 3b. ContratoPdfService::montarDados() resolve override-ou-composto e
  entrega pagamento.plano_parcelas pronto; ContratoVariaveisModeloService::
  mapa() so le essa chave - a classe continua PURA (T-126-40), sem
  consultar DB/Http/Log/Cache/Storage. A composicao segue o precedente real
  do juridico: quantidade em digito + extenso entre parenteses, valor
  R$ 0.000,00, sem valor por extenso. 1 fase (ou nomes de servico distintos
  no snapshot - o caso multi-servico generico que montarDados() ainda
  suporta) cai no caso simples (PLANO_PARCELAS_CASO_SIMPLES, constante nao
  alterada). Ultima fase sem periodo termina em "e as demais seguirao a
  faixa apurada na forma da Clausula 2.1.2.".
- concatenarServicos() passou a fazer array_unique() pelo nome - duas fases
  do mesmo servico nao viram mais "Gestao e Gestao" em
  servico_contratado (bug latente descoberto ao implementar a Tarefa 1,
  corrigido junto por Rule 1 - ver deviation abaixo).
- 3c. ContratoAdminController::show() expoe plano_parcelas_texto (override
  cru) e plano_parcelas_efetivo (override ou composto) por contrato.
  atualizarCadastro() aceita contratos[].plano_parcelas_texto, com o mesmo
  padrao de checagem de pertencimento (IDOR) usado para contratos_servico[]
  - valida TODOS os pares antes de gravar qualquer coisa. ContratoDetalhe.jsx
  ganhou um campo de texto editavel por contrato em rascunho (antes de ser
  enviado pela Clicksign), salvo pela mesma rota admin.contratos.cadastro.

### Copy do JSX

MOTIVO_BLOQUEIO_TEXTO.servicos_duplicados deixou de dizer "corrija no
HubSpot deixando so um lancamento por servico" (agora seria conselho
errado) e passou a explicar que a ORDEM das parcelas nao pode ser
determinada, pedindo para corrigir a data de inicio no HubSpot.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] concatenarServicos() duplicava o nome do servico em servico_contratado para pagamento escalonado**
- Encontrado durante: implementacao da Tarefa 3b.
- Problema: com o snapshot passando a carregar 2+ fases do MESMO servico
  (Tarefa 1), concatenarServicos() concatenava os nomes SEM deduplicar -
  produziria "Gestao de Ads e Gestao de Ads" no documento assinado.
- Fix: array_unique() pelo nome antes de concatenar.
- Arquivo: app/Services/Clicksign/ContratoVariaveisModeloService.php.
- Commit: 64eff2fe.

**2. [Rule 2 - Correcao de escopo] comporPlanoParcelasDasFases() so compoe quando todas as entradas sao do MESMO servico**
- Encontrado durante: rodada de testes (o snapshot multi-servico generico -
  "um envelope por empresa", D-19 - que montarDados() ainda suporta
  produzia texto sem sentido tipo "As 0 (zero) primeiras parcelas..."
  quando tratado como se fossem fases de um pagamento escalonado).
- Fix: guarda count(array_unique(nomes)) === 1 antes de entrar na
  composicao multi-fase; caso contrario cai no caso simples (comportamento
  identico ao de antes deste quick).
- Arquivo: app/Services/ContratoPdfService.php.
- Commit: 64eff2fe.

Nenhuma outra automacao alem destas duas - o resto seguiu o plano
literalmente.

## Testes

Novos, cobrindo a lista do plano:

- tests/Feature/Phase127/ContratoClicksignServiceTest.php - 3 testes novos:
  duas fases com ordem derivavel (Mons Bike, ordem invertida na entrada
  incluida), ultima fase sem periodo, caminho automatico via Observer
  dentro de DB::transaction().
- tests/Feature/Phase126/ContratoPdfDadosTest.php - 5 testes novos:
  valor_mensal soma so a primeira fase, caso simples legado, frase composta
  da Mons Bike, ultima fase sem periodo, override.
- tests/Feature/Phase126/ContratoVariaveisModeloTest.php - 3 testes novos:
  composicao de 2 fases, override, dedupe de servico_contratado.
- tests/Feature/Phase131/ContratoAdminDetalheTest.php - 5 testes novos:
  show() deixa de bloquear com ordem derivavel, gerar cria UM contrato com
  as duas fases, show() devolve plano_parcelas_texto/_efetivo,
  atualizarCadastro() grava o override, IDOR do override.

Os testes pre-existentes de servicos_duplicados (ambos SEM data de inicio -
caso genuinamente ambiguo) continuam passando sem alteracao: a mudanca de
comportamento e aditiva, nao regressiva, para esse cenario especifico.

## Gate

C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase126|Phase127|Phase131|Phase132|Phase133"

Tests: 388, Assertions: 1318 - 100% verde ("OK, but there were issues!"
refere-se so a 450 PHPUnit Deprecations pre-existentes, nao relacionadas a
este quick - mesma contagem do baseline medido antes de qualquer mudanca).

Baseline antes deste quick (mesmo filtro): 372 tests, 1276 assertions. Este
quick acrescentou 16 testes e 42 assertions, todos verdes.

## npm run build

npm run build rodou com sucesso ao final - ContratoDetalhe-0Kx3Ft7Y.js
gerado sem erros (JSX mexido: copy do bloqueio + campo editavel da frase).

## Restricoes operacionais respeitadas

- Nenhum git add -A / git add . / git commit -a / git stash - todos os
  commits usaram paths explicitos, conferidos por git status --porcelain
  antes de cada commit.
- Nenhum arquivo de outra sessao (tests/Feature/CompanyPortfolioAccessTest.php
  e o restante da arvore compartilhada, listados como ?? no status) foi
  tocado ou commitado.
- Nenhum deploy. Nenhuma alteracao em .env, VPS, Clicksign ou
  servicos.clicksign_template_id.

## Commits

- 281ff02e - feat: coluna plano_parcelas_texto em contrato_assinaturas
- de66059f - feat: guarda de servico duplicado vira consolidacao de fases
- 64eff2fe - feat: valor_mensal para de somar fase e plano_parcelas passa a compor a frase
- ae6851ad - feat: tela edita a frase do parcelamento e copy do bloqueio muda de significado

## Self-Check: PASSED

- app/Services/Clicksign/ContratoClicksignService.php - FOUND, com
  ordenarFasesOuNull()/parcelasDoPeriodo().
- app/Services/ContratoPdfService.php - FOUND, com planoParcelas()/
  comporPlanoParcelasDasFases()/numeroPorExtenso().
- app/Services/Clicksign/ContratoVariaveisModeloService.php - FOUND,
  mapa() le pagamento.plano_parcelas.
- app/Http/Controllers/ContratoAdminController.php - FOUND, show()/
  atualizarCadastro() atualizados.
- resources/js/Pages/Admin/ContratoDetalhe.jsx - FOUND, campo editavel +
  copy nova.
- database/migrations/2026_08_24_100000_add_plano_parcelas_texto_to_contrato_assinaturas_table.php - FOUND.
- Commits 281ff02e, de66059f, 64eff2fe, ae6851ad - FOUND em git log.
