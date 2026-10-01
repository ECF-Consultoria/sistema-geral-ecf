# Fase 159 — Pessoa com dois cargos (estrategista + analista) e junção das contas do Danilo — CONTEXT

**Coletado em:** 2026-09-30, sessão direta com o usuário (sem discuss-phase formal — as decisões abaixo vieram de pergunta explícita).
**Por que é fase GSD:** altera tabela existente com dado em produção (`user_setores`) e a junção das contas mexe em `*_snapshots` e em atribuições de NPS que alimentam bonificação (CLAUDE.md, "GSD obrigatório").

## O problema

O Danilo atende as duas funções: em algumas lojas é estrategista, em outras analista, em algumas as duas. Hoje o sistema tem **dois usuários** para ele — `users.id 15` "Danilo" (cargo analista) e `users.id 35` "Danilo Estrategista" — porque o cadastro não comporta uma pessoa nos dois papéis. Consequência: a nota de desempenho sai em duas contas e alguém soma à mão, e **a média das duas contas não é a nota certa** (as lojas em que ele é os dois entram nas duas contas, e faturamento/margem delas pesam 2×).

## O que já funciona e NÃO deve mudar

- **O cálculo da nota já suporta papel duplo.** `NpsPorEmpresaService` regra D-02 (Fase 118): quem acumula estrategista e analista na mesma loja entra com a MÉDIA das duas perguntas e a loja pesa 1×. Coberto por `tests/Feature/Phase118/NpsPorEmpresaContratoTest.php` (`test_d02_papeis_acumulados_...`).
- A carteira (`CarteiraContextService::forUser`) aceita as duas `role` da pivot e os consumidores fazem `unique()` por `company_id` (`CompanyScoreService`, `NpsPorEmpresaService`, `FinancialSourceResolver`).
- Régua, faixa de bônus e `fonte_financeira` não dependem de cargo.
- `company_users` já aceita o mesmo user em duas `role` na mesma empresa: unique `(company_id, user_id, role, servico_id)`.
- `nps_score_assignments` não tem unique; o snapshot gera uma linha por (serviço, papel, responsável) — duas para quem acumula.

**`DesempenhoScoreService` não deve ter o cálculo alterado nesta fase.** Se algo exigir, parar e perguntar (hash gate da Fase 119 em 5 arquivos + bump de cache — learnings §0.01 e §5).

## Decisões travadas

### D-01 — Uma pessoa pode ter mais de um cargo no MESMO setor
Hoje `user_setores` tem `unique(user_id, setor_id)` e um `cargo_id` por linha → um cargo por setor. Analista e estrategista são ambos do setor Performance (e ambos existem também no Shopee). Decisão do usuário: **"o certo é permitir dois cargos para a mesma pessoa"** (rejeitada a alternativa de só afrouxar os selects).
- O desenho de schema vai POR ESCRITO no plano antes da migration (CLAUDE.md, disciplina 2).
- MariaDB (learnings §6): o unique `(user_id, setor_id)` pode ser o índice de apoio da FK `user_id` — **criar o índice novo ANTES de dropar o antigo** (foi exatamente o 1553 da migration 140001, learnings §10.1), migration idempotente sem engolir erro em silêncio, nomes de índice ≤ 64 chars. Verificação que vale é `SHOW INDEX` no MariaDB, não o SQLite dos testes.
- `is_principal` continua existindo; definir a regra quando há duas linhas no mesmo setor.

### D-02 — Tela /users permite marcar mais de um cargo no mesmo setor
`UserController::syncVinculos` hoje faz `updateOrInsert` por `(user_id, setor_id)` — um segundo cargo sobrescreve o primeiro. `resources/js/Pages/Users/Index.jsx` impede escolher o mesmo setor duas vezes.

### D-03 — Cadastro de empresa aceita a mesma pessoa como analista E estrategista
`CompanyController::update` (origin/main ~L1144-1172) tem a regra "mesma pessoa nos dois papéis continua valendo só como analista — regra herdada, preservada de propósito", e o `$sync` é indexado por `user_id` (só cabe um papel por pessoa). **Essa regra está revogada pela decisão do usuário.** Gravar uma linha por papel; o histórico (`company_manager_history`) registra os dois papéis. Conferir o mesmo padrão em qualquer outro caminho que grave responsável (Shopee, bulkAssign, Distribuição pela Coordenação/Líder da v23.0).

### D-04 — Selects de responsável listam a pessoa em cada cargo que ela tem
Quem tem os dois cargos aparece no select de analista E no de estrategista (CompanyController, Shopee, filtros do Dashboard admin, telas da v23.0 de distribuição).

### D-05 — Ranking e Relatório de Bonificação: NAS DUAS ABAS (decisão do usuário)
Com filtro por cargo, a pessoa aparece em cada aba de cargo que tem, **com a mesma nota**. Sem filtro, aparece **uma vez**. A nota, a posição geral e o bônus são únicos. Hoje: `PerformanceController` faz `keyBy('user_id')` sem ordem (cargo arbitrário) e filtra depois; `RelatorioBonificacaoController` filtra antes do `keyBy`; `BonusAuditoriaController` usa `keyBy` arbitrário; `PortfolioController` usa `->value('c.slug')` sem ordem. Unificar o comportamento.

### D-06 — Junção das contas a partir da competência 2026-09 (decisão do usuário)
Setembro/2026 já fecha com nota única. Competências **já fechadas ficam intocadas** (learnings §2: recálculo já tirou bônus de alguém — o próprio Danilo, junho/2026).
- Comando Artisan com `--dry-run` como padrão e `--apply` explícito, backup antes, conferência por **reconsulta ao banco** (learnings §4), nunca por stdout.
- Mover os vínculos `company_users` do user 35 para o 15 (cuidado com colisão no unique quando o 15 já tem a mesma linha).
- Atribuições de NPS cuja **competência** é ≥ 2026-09 (competência = mês do `completed_at` − 1, learnings §5.2) e imputações da mesma janela: do 35 para o 15. **A coleta do NPS de setembro começa em 01/10** — respostas que chegarem antes da junção nascem atribuídas ao 35.
- Snapshots de desempenho do 35 com origem `warm_cache` em 2026-09: remover; os de `consolidar_mes` NUNCA. Derrubar as chaves de cache dos dois users (learnings §0.025 passo 3).
- Dar ao user 15 o cargo estrategista no Performance (usa D-01) e desativar o 35 — sem apagar (o histórico dos meses fechados continua no nome dele).
- Pesquisar: a carteira da competência respeita `assigned_at`/histórico? Se sim, a junção precisa preservar a data de vínculo original.
- Operação em produção roda **depois** do deploy e com o usuário ciente — o plano trata como passo separado.

### D-07 — Formulário de NPS do cliente: mantém as duas perguntas
Quando estrategista e analista são a mesma pessoa, o cliente continua respondendo uma pergunta por função ("Estrategista: Danilo" / "Analista: Danilo"). É o que alimenta a média D-02. Sem mudança.

### D-08 — Menu com cargo excluído (`excludeRoles` do AppLayout)
Hoje o item some se QUALQUER cargo da pessoa estiver excluído (ex.: "Alertas Estratégicos" exclui `analista`) — com os dois cargos ele perderia o item de estrategista. Padrão adotado (reportar ao usuário): **o item aparece se pelo menos um cargo da pessoa tem acesso**. Não muda nada para quem tem um cargo só.

### D-09 — Ramo legado de NPS usa UMA dimensão por pessoa
`User::dimensaoNpsDesempenho()` escolhe um cargo; o ramo legado (respostas sem atribuição congelada no papel) em `NpsPorEmpresaService`/`DesempenhoScoreService` usa essa dimensão para a pessoa inteira. Com dois cargos, numa loja em que ele é só analista poderia receber a nota do estrategista. **Medir antes de mexer:** quantas respostas das lojas do Danilo caem no ramo legado na competência 2026-09. Se zero e sem caminho para surgir, documentar e não tocar o motor. Se houver, decidir com o usuário (toca motor de bônus).

### D-10 — `/admin/setores/{setor}` (SetorMembroController) ENTRA no escopo
Achado da pesquisa: é o segundo caminho que grava `user_setores`. Hoje bloqueia o 2º vínculo no mesmo setor ("Usuário já é membro deste setor") e o `destroyMembro()` apagaria as duas linhas de cargo de uma vez. Deixá-lo de fora faria uma tela desfazer o que a outra grava. Adicionar cargo a quem já é membro passa a ser permitido; remover passa a ser por cargo.

### D-11 — Dados do user 35 em metas, onboarding e Demandas Dev: medir antes de decidir
`portfolio_goals`, responsável de onboarding e Demandas Dev não foram aprofundados. A primeira tarefa da etapa de dados é uma LEITURA em produção (autorizada pelo usuário no momento) contando linhas do user 35 em cada tabela com `user_id`. Tabela com zero linhas sai do comando; com linhas, entra na regra mover/manter do plano.

### D-12 — Gate de hash da Fase 119 vermelho é PRÉ-EXISTENTE e fica fora
Medido na pesquisa, sem nenhuma alteração: 17 de 29 testes de `tests/Feature/Phase119/` falham pelo hash de `DesempenhoScoreService.php` (a constante não foi rotacionada — mesmo incidente do learnings §0.01). Esta fase não toca esse arquivo, então não rotaciona a constante. Registrar em `BASELINE-TESTES.md` para o verifier não atribuir essas falhas à Fase 159.

## Fora do escopo (registrado)
- `componentes.nps_medio` (o "NPS médio" exibido) pesa a loja dupla 2× — é metadado, não decide bônus, e mora no `DesempenhoScoreService`. Não mexer nesta fase.
- Distorções de tela: "últimas respostas" do painel da carteira mostra a mesma resposta 2× sem rótulo de papel; `/portfolio/{id}` (tela antiga, `renderPortfolio`) duplica empresa com `withPivot('role')`. Corrigir só se for barato e sem tocar o motor.

## Referências
- `.planning/learnings/desempenho-bonificacao.md` §2, §4, §5, §6, §7, §10.1
- Memória local: carteira do Danilo aplicada da planilha em 23/09 (user 15 = 21 lojas analista, user 35 = 17 estrategista; backup `/root/backup_company_users_danilo_260923.json`).
- Banco LOCAL não serve para decidir nada de empresa/responsável (ids apontam para pessoas diferentes) — medições de D-06/D-09 são em produção, leitura.
