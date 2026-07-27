# Phase 116: NPS não respondido conta como nota mínima (1) - Context

**Gathered:** 2026-07-27
**Status:** Ready for planning
**Source:** Pedido direto do usuário (2026-07-27), com decisões de rota e retroatividade já tomadas via AskUserQuestion.

<domain>
## Phase Boundary

Muda a regra de agregação do NPS: **todo NPS efetivamente disparado e não respondido passa a valer nota 1** (mínima da escala), em todos os lugares que consomem a média de NPS.

Pedido do usuário (verbatim):

> "Para trazer uma sensação de dever no envio do NPS todos NPS não respondidos devem ser considerados como nota minima(1). Isso deve valer tanto para area NPS e tambem para a desempenho e outros lugares que usam a nota media do NPS. Implementando isso naturalmente a nota media no NPS do pessoal ira cair, isso já é esperado. Se o mes passar e não ainda continuar sem responder a nota definiva no NPS não respondido será 1."

**Motivação de negócio:** criar senso de dever no envio/cobrança do NPS. A queda da média das pessoas é **esperada e desejada** — não é regressão.

Entrega:
1. NPS disparado sem resposta conta como nota 1 na **área NPS** (médias, listagens, dashboards do módulo).
2. Mesma regra no **Desempenho/bonificação** (nota de NPS que entra no score do bônus).
3. Mesma regra em qualquer outro consumidor de média de NPS (carteira, relatórios, dashboards).
4. **Backfill retroativo** das competências já fechadas, com relatório de impacto antes/depois.
5. UI da área NPS explicita a regra em linguagem simples.

**FORA do escopo:** mudar a mecânica de disparo do NPS, os modelos/templates, os canais (email/Digisac), ou os pesos do bônus.

</domain>

<decisions>
## Decisões travadas (LOCKED — não reperguntar)

### D1 — Retroatividade: SIM, com backfill
Decisão do usuário (2026-07-27): a regra vale **retroativamente** nas competências já fechadas. O usuário foi informado e aceitou que isso pode mudar notas e possivelmente **quem bateu o bônus em meses fechados**.

**Mitigação obrigatória:** o comando de backfill roda primeiro em modo `--dry-run` produzindo um **relatório antes/depois por pessoa e competência**, para o usuário conferir o impacto **antes** de aplicar. Só aplica com confirmação explícita.

### D2 — Quando o 1 passa a valer
Vale **desde o disparo**, enquanto a competência ainda está aberta (a média já reflete o não respondido). Quando o mês fecha sem resposta, o 1 vira **definitivo** — resposta que chegue depois do fechamento da competência não reescreve a nota daquela competência.

### D3 — Só conta o que foi disparado
Só vira nota 1 o NPS **efetivamente disparado** (existe survey/envio para aquela empresa+responsável+competência). Empresa sem disparo **nunca** entra como 1 — senão pune quem não tinha o que enviar. Este é o invariante mais importante da fase.

### D4 — Escala e valor
Nota mínima = **1** (não 0). **CONFIRMADO pela pesquisa (Q7):** a escala real do produto é **1 a 5** (`NpsTemplateOption.peso` é 1..5, `average_score` é `decimal(5,2)` dentro de `[1.00, 5.00]`). Logo "nota mínima(1)" mapeia direto ao piso da escala, sem ambiguidade. `DesempenhoScoreService` não reescala — a nota entra no bônus na mesma escala 1-5.

### D5 — Empresa invalidada na competência NÃO entra em lugar nenhum
Decisão do usuário (2026-07-27, respondendo à Open Question 1 do RESEARCH): empresa invalidada na competência (`bonus_invalidacoes`, tela `/desempenho/auditoria-bonus`) **não puxa nota 1 nem no bônus, nem na área NPS**. Invalidou, saiu da regra inteira naquela competência.

**Consequência de escopo (importante):** hoje a área NPS (`NpsController`) **não conhece** `bonus_invalidacoes` — ela só respeita a invalidação manual de resposta (`NpsResponse.invalidated_at`), que é outro conceito. Esta decisão **adiciona** o filtro de invalidação por competência à área NPS. Não é correção de bug, é capacidade nova — planejar explicitamente e testar.

### D6 — Disparo manual segue a mesma regra
Decisão do usuário (2026-07-27, Open Question 2 do RESEARCH): NPS disparado manualmente (`NpsController::generate()`, `auto_generated=false`) **também vira 1** se não for respondido. Regra única, sem brecha de "escolher o canal que não conta".

**Consequência técnica:** surveys manuais podem não ter `month_reference` — o agrupamento por mês usa `created_at` como fallback. A imputação precisa cobrir esse fallback. O plano deve ler `NpsController::generate()` (não lido em profundidade na pesquisa) antes de fechar o grão da tabela.

### D7 — A dimensão EMPRESA também recebe o 1
Decisão do usuário (2026-07-27, Open Question 3 do RESEARCH): além das notas de pessoa (estrategista/analista), a média da própria **empresa** também recebe a nota 1 do não respondido. A área NPS mostra 3 médias na mesma tela — deixar a da empresa de fora faria os números da mesma tela discordarem entre si.

**Consequência técnica:** `NpsSnapshotService::DIMENSAO_ROLE` hoje só gera assignment de pessoa para analista/estrategista; a dimensão `empresa` só existe em `nps_response_scores` e alimenta os `$cards['empresa']`. A imputação precisa de linha própria para a dimensão empresa (sem `role`/`user_id`), ou mecanismo equivalente.

</decisions>

<constraints>
## Restrições e armadilhas conhecidas (do recon + memória do projeto)

### C1 — Desafio estrutural: não respondido HOJE não existe como registro
`nps_score_assignments` só nasce **quando a resposta chega** (via `app/Services/Nps/NpsSnapshotService.php`). NPS não respondido simplesmente não tem linha — a média hoje o ignora silenciosamente.

Duas arquiteturas possíveis (escolha fica para o plan-phase, com trade-offs explícitos):
- **(a) Materializar** assignments de nota 1 para surveys disparados sem resposta (mais simples de ler, exige job/command que fecha a competência e cuidado com idempotência).
- **(b) Agregação conta o não-respondido** em tempo de leitura (sem escrever linha fantasma, mas exige tocar todos os pontos de agregação e fica mais caro/repetido).

### C2 — `bonus_invalidacoes` tem precedência
Empresa invalidada na competência **não pode puxar nota 1**. A invalidação por competência (tela `/desempenho/auditoria-bonus`, tabela `bonus_invalidacoes`) já tira a empresa do bônus financeiro E do NPS — a nova regra tem que respeitar isso, senão a invalidação vira punição.

### C3 — Não confundir com o gap do responsável CONSOLIDADO
Existe um gap conhecido: NPS de responsável **consolidado** nasce sem assignment porque `company_users.servico_id` é NULL e não casa em `consultorDoServico`/`estrategistaDoServico`. Isso é **ausência de assignment por bug**, não "não respondido". Se a fase materializar 1 para tudo que não tem assignment, esse gap vira nota 1 indevida. Distinguir pela existência do **disparo/survey**, não pela ausência de assignment.

### C4 — Janela de competência do NPS
O NPS já usa **competência M mesmo lendo M+1** (regra da auditoria de bônus, v19). O "vira definitivo quando o mês fecha" tem que respeitar essa janela — não fechar cedo demais e marcar 1 em quem ainda está dentro do prazo de resposta.

### C5 — Multi-modelo (v16.0)
O disparo é **multi-modelo por serviços cobertos**. O não respondido pode ser **parcial**: a pessoa respondeu um modelo e não outro. A regra precisa ser por (empresa × responsável × serviço/modelo × competência), não por empresa inteira. `->principal()` é ramo legado — não usar como base.

### C6 — Cache do Desempenho
`DesempenhoScoreService` usa `computeCached()` com cacheKey versionada `desempenho.compute.vN`. Mudar o comportamento do compute **exige bump da versão** — e a string está **hardcoded em vários testes** (Phase96/V16/V18), que precisam ser atualizados junto. Depois de deployar, rodar `php artisan cache:clear` no VPS.

### C7 — Enum + SQLite nos testes
Se a fase adicionar valor a algum enum (ex.: status de assignment tipo `nao_respondido`), a migration precisa de branch SQLite (`string()->change()` sem CHECK), senão os Feature tests quebram.

</constraints>

<codebase>
## Mapa do recon (ponto de partida — o plan-phase deve confirmar)

**Serviços**
- `app/Services/Nps/NpsSnapshotService.php` — cria os `nps_score_assignments` quando a resposta chega
- `app/Services/DesempenhoScoreService.php` — nota de NPS do bônus

**Controllers**
- `app/Http/Controllers/NpsController.php` — área NPS, médias, listagens
- `app/Http/Controllers/PerformanceController.php`

**Models**
- `NpsSurvey`, `NpsSurveyEvent` — o disparo (fonte da verdade de "foi enviado")
- `NpsResponse`, `NpsResponseScore`, `NpsResponseCoveredService` — a resposta
- `NpsScoreAssignment` — a nota atribuída a cada responsável

**Commands existentes (padrão para o backfill novo)**
- `app/Console/Commands/NpsBackfillAssignmentsConsolidado.php`
- `app/Console/Commands/NpsBackfillDivisorTextoLivre.php`
- `app/Console/Commands/NpsDispararMensal.php` — o disparo mensal

**Testes que provavelmente vão quebrar / precisam de atualização**
- `tests/Feature/V16/BonusAtribuicoesNpsTest.php`
- `tests/Feature/V16/AtribuicaoPorServicoNpsTest.php`
- `tests/Feature/V16/AtribuicaoConsolidadoNpsTest.php`
- `tests/Feature/V18/JanelaNpsBonusTest.php`
- `tests/Feature/V18/ConsolidarMesJanelaNpsTest.php`
- `tests/Feature/BonusInvalidacaoEmpresaTest.php`
- `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php`
- `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` (cacheKey hardcoded)

</codebase>

<ui>
## UI hint: SIM

A área NPS precisa deixar **explícito na tela** que não respondido conta nota 1 — regra do projeto: nada de jargão sem explicação. Ex.: nas médias, distinguir visualmente "respondidos" × "não respondidos contados como 1", com uma linha de texto simples explicando a regra. Evitar termo técnico ("assignment", "imputação", "penalização") na UI.

Design system: Tailwind com tokens `ecf-*`, dark theme, `DevCard`/`cn()` existentes.
</ui>

<verification>
## Critérios de aceite

1. NPS disparado e não respondido aparece como nota 1 na média da área NPS.
2. Mesma nota 1 refletida no score de Desempenho/bonificação.
3. Empresa **sem disparo** não entra na conta (invariante D3) — teste explícito.
4. Empresa **invalidada** na competência não puxa 1 (C2) — teste explícito.
5. Responsável consolidado sem assignment por gap conhecido não vira 1 indevido (C3) — teste explícito.
6. Não respondido **parcial** (multi-modelo) conta 1 só no modelo não respondido (C5) — teste explícito.
7. Resposta que chega depois do fechamento da competência não reescreve a nota daquela competência (D2).
8. Comando de backfill é **idempotente** e tem `--dry-run` com relatório antes/depois por pessoa e competência.
9. Suite de testes existente verde (com as atualizações de cacheKey/fixtures necessárias).
</verification>
