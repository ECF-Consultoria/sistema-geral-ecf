---
phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa
plan: 05
subsystem: fechamento-mensal / notificações
tags: [fechamento, notificacoes, idempotencia, concorrencia, faixa-faturamento]
dependency-graph:
  requires:
    - "138-02: colunas notificado_em/notificado_faixa_ordem, Categoria::FAIXA_ALTERADA, FaixaAlteradaNotification"
    - "138-03: ConsolidarMesFechamento classificando o grupo pela tabela do grupo"
  provides:
    - "App\\Services\\Fechamento\\FechamentoFaixaNotifier"
    - "Passo 8 (aviso de mudança de faixa) em fechamento:consolidar-mes"
  affects: []
tech-stack:
  added: []
  patterns:
    - "Lock nomeado por competência (Cache::lock, não-bloqueante) guardando seleção+envio+carimbo — mesmo mecanismo de RefreshGrossBillingCacheJob/MercadoLivreService, aplicado a uma nova fresta de corrida"
    - "Chamada de efeito colateral não-crítico isolada em try/catch que loga e não altera exit code (mesmo padrão do gate de qualidade do Passo 6, mas na direção oposta: aqui a falha NUNCA bloqueia)"
key-files:
  created:
    - app/Services/Fechamento/FechamentoFaixaNotifier.php
    - tests/Feature/Phase138/Phase138AvisoMudancaFaixaTest.php
  modified:
    - app/Console/Commands/ConsolidarMesFechamento.php
decisions:
  - "Concorrência: lock nomeado por competência (Cache::lock('fechamento:notificar:{mes}', 60)), não lockForUpdate() nas linhas — a seleção lê 3 fontes (2 tabelas de snapshot da competência atual + 1 consulta agregada da competência anterior para montar 'faixa antiga → faixa nova'); lockForUpdate() só cobriria as 2 primeiras. Um lock nomeado cobrindo seleção+envio+carimbo como bloco único fecha a fresta inteira de uma vez"
  - "Lock tentado em modo NÃO-bloqueante (->get(), sem callback): se outra execução já está processando a competência, esta simplesmente não processa nada — quem está com o lock vai carimbar exatamente as mesmas mudanças que esta encontraria, então bloquear/esperar não traria nenhum aviso a mais, só atrasaria o comando"
  - "Envio agregado — uma notificação por rodada, nunca uma por empresa (127 empresas em estado ok em produção); carimbo continua por LINHA, então uma rodada seguinte que mexa numa única empresa gera aviso curto só sobre ela"
metrics:
  duration: "~55min"
  completed: 2026-09-03
---

# Phase 138 Plan 05: FechamentoFaixaNotifier + chamada no fechamento Summary

`FechamentoFaixaNotifier` — serviço que avisa os admins quando uma empresa ou grupo muda de faixa
no fechamento mensal, nos dois sentidos (subida E queda), com trava de idempotência sequencial
(D-03) reforçada por exclusão mútua contra execução concorrente, e a chamada dele ao fim de
`fechamento:consolidar-mes`.

## O que foi entregue

**Tarefa 1 — `FechamentoFaixaNotifier`.** Método público `notificar(Carbon $mes): array` que:

1. Tenta adquirir `Cache::lock('fechamento:notificar:{mes}', 60)` em modo NÃO-bloqueante. Se
   ocupado, registra `Log::info` e sai sem processar nada (ver decisão de concorrência abaixo).
2. Dentro do lock, roda inteiro dentro de `DB::transaction()`: seleciona linhas de
   `fechamento_snapshots`/`fechamento_grupo_snapshots` da competência com `origem = consolidar_mes`,
   `estado = ok`, `faixa_ordem` não nulo, `evolucao` em `('subiu','desceu')` e ainda não avisadas
   (`notificado_em IS NULL OR notificado_faixa_ordem <> faixa_ordem`).
3. Sem nenhuma linha elegível: sai sem enviar nada.
4. Sem nenhum admin (`User::where('role','admin')`): registra `Log::warning` e sai SEM carimbar —
   carimbar sem destinatário faria a mudança sumir para sempre.
5. Busca a faixa anterior de cada empresa/grupo com UMA consulta por tabela na competência anterior
   (indexada por `company_id`/`company_group_id`), monta a copy sem jargão ("Fulano: 3ª → 4ª faixa"
   quando a faixa anterior é conhecida, "Fulano: subiu de faixa" quando não é; grupos prefixados
   "Grupo"; no máximo 10 nomes por sentido, com "e mais N"), envia UMA `FaixaAlteradaNotification`
   por admin via `Notification::send()`, e carimba `notificado_em`/`notificado_faixa_ordem` só nas
   linhas efetivamente citadas.

**Tarefa 2 — chamada no comando.** `FechamentoFaixaNotifier` injetado no construtor de
`ConsolidarMesFechamento` (promoção de propriedade, mesmo padrão dos outros três serviços). Chamado
no Passo 8, logo após o `writer->sync()` bem-sucedido, dentro de `try/catch (\Throwable)` que loga
`[Fechamento]` e NUNCA altera o exit code — o exit code reflete só a qualidade do congelamento, e é
ele que `FechamentoController` usa para devolver 409 na tela. Os contadores (`empresas`, `grupos`,
`notificacoes`) entram no `$this->info()` final como conveniência operacional; o docblock da classe
foi atualizado mencionando o Passo 8.

**Tarefa 3 — gate.** `Phase122|Phase136|Phase137|Phase138`: **276 testes / 1452 asserções / 0
falhas** (medido após os dois commits desta plano, árvore com o trabalho paralelo do plano 138-06
já presente). Nenhum teste da 137 precisou de ajuste — `Phase137ConsolidarMesTest` não faz nenhuma
asserção sobre a tabela `notifications`.

## Correção obrigatória do plan-checker — concorrência

A trava `notificado_em`/`notificado_faixa_ordem` protege execução SEQUENCIAL (inclusive o
incidente real de "Refazer" clicado 3x seguidas), mas não protege duas execuções de verdade em
paralelo: ambas podiam rodar a seleção antes de qualquer uma commitar o carimbo.

**Saída escolhida: (b) lock nomeado por competência** (`Cache::lock('fechamento:notificar:'.$mesStr,
60)`), não a opção (a) `lockForUpdate()`.

**Por quê.** A seleção deste notificador não é só um `SELECT ... FOR UPDATE` nas duas tabelas de
snapshot: para montar a copy "3ª → 4ª faixa" ela também lê, numa 3ª consulta agregada, a competência
ANTERIOR (que não faz parte da seleção elegível e não deveria ser travada). `lockForUpdate()` nas
linhas selecionadas cobriria as duas primeiras consultas, mas deixaria a leitura da competência
anterior fora da exclusão mútua — não fecharia a fresta inteira. Um lock nomeado por competência,
guardando a rodada INTEIRA (seleção + leitura da competência anterior + envio + carimbo) dentro de
um único `try/finally`, resolve isso de uma vez, com o mesmo mecanismo já usado em outros pontos do
projeto (`RefreshGrossBillingCacheJob`, `MercadoLivreService::renovarToken`, `AcervoEscritaLock`).

**Modo não-bloqueante.** O lock é tentado com `->get()` (sem callback), não `->block()`. Se outra
execução já está com o lock, a segunda simplesmente não processa nada — a execução que já tem o
lock vai carimbar exatamente as mesmas mudanças que a segunda encontraria, então esperar não traria
nenhum aviso a mais, só atrasaria `fechamento:consolidar-mes` sem necessidade.

**Teste obrigatório** —
`Phase138AvisoMudancaFaixaTest::segunda_execucao_que_comeca_com_o_lock_ocupado_nao_duplica_o_aviso`:
adquire o lock nomeado manualmente no teste (simulando uma primeira execução que já passou da
seleção e ainda não carimbou), chama `notificar()` e confirma por RECONSULTA ao banco que nenhuma
notificação foi criada e nenhum carimbo aconteceu; libera o lock e confirma que a chamada seguinte
processa normalmente, com exatamente 1 notificação gravada.

## Deviations from Plan

**1. [Rule 1 - Bug no próprio teste, não no código de produção] `DatabaseNotification::latest('id')`
não ordena por data.** Durante a Tarefa 1, o teste de "refazer + mudar faixa de uma empresa" falhou
citando as DUAS empresas na notificação nova, quando deveria citar só a que mudou. Investigação por
`fwrite(STDERR, ...)` mostrou que a seleção do notificador já estava correta (só 1 linha elegível);
o teste é que buscava "a notificação mais nova" com `DatabaseNotification::latest('id')` — e o `id`
de `DatabaseNotification` é UUID (não auto-increment), então ordenar por ele não dá ordem
cronológica. Corrigido capturando os ids existentes ANTES da 2ª rodada e filtrando
`whereNotIn('id', $idsAntes)` para isolar só a notificação nova. Nenhuma mudança em código de
produção.

## Verificação

- `FechamentoFaixaNotifier::notificar()` seleciona só linhas `estado=ok` + `faixa_ordem` não nulo +
  `evolucao` em `('subiu','desceu')` + não avisadas — confirmado pelos 9 testes de
  `Phase138AvisoMudancaFaixaTest`.
- Duas chamadas seguidas sem mudança produzem exatamente 1 notificação no total (não 2).
- Mudar a faixa de UMA empresa depois do 1º aviso produz um 2º aviso citando só ela.
- Sem admin cadastrado: 0 notificações E 0 carimbo (reconsulta ao `fresh()->notificado_em`).
- Segunda execução com o lock ocupado: 0 notificações, 0 carimbo; depois do release, processa
  normalmente.
- Copy sem jargão: mensagem testada por `assertStringNotContainsStringIgnoringCase` contra
  "snapshot", "competência", "reconsolidação", "rollup", "ordem".
- `fechamento:consolidar-mes`: falha no notificador (try/catch) não altera o exit code — coberto
  indiretamente pelo isolamento do bloco (nenhum teste da 137/138 quebrou com a chamada adicionada).
- Gate `Phase122|Phase136|Phase137|Phase138`: **276 testes / 1452 asserções / 0 falhas**.

## Commits

- `dd019204` — feat(138-05): FechamentoFaixaNotifier com trava de concorrência por lock nomeado
- `f6d64438` — feat(138-05): chama o notificador de mudança de faixa ao fim do fechamento

## Known Stubs

Nenhum. O aviso é funcional de ponta a ponta: é gravado como `FaixaAlteradaNotification` real na
tabela `notifications`, lido pelo mesmo sino/tela que a 138-02 já rotulou como "Mudança de faixa".

## Threat Flags

Nenhum novo — a superfície nova (o notificador em si) já estava registrada e mitigada no
`<threat_model>` do próprio plano (T-138-12 a T-138-15). A correção de concorrência do plan-checker
é a mitigação completa de T-138-12 (a versão original, só com as colunas de idempotência, cobria
apenas o caso sequencial).

## Self-Check: PASSED

- `app/Services/Fechamento/FechamentoFaixaNotifier.php` — FOUND
- `tests/Feature/Phase138/Phase138AvisoMudancaFaixaTest.php` — FOUND
- `app/Console/Commands/ConsolidarMesFechamento.php` (modificado) — FOUND
- Commit `dd019204` — FOUND
- Commit `f6d64438` — FOUND
