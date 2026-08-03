---
phase: 122-persist-ncia-por-empresa-e-comandos-v21-0
created: 2026-08-03
source: sessão de 2026-07-31/08-03 (execução da Fase 121 + debug + quick 260731-pvk)
---

# Contexto herdado — o que mudou entre o planejamento da 121 e agora

Não é discussão de escopo (o ROADMAP já define os 5 critérios). É o estado real do sistema que o planner precisa conhecer e que não está em nenhum artefato da 121.

## 1. O GATE da 121 foi aprovado COM RESSALVAS — e a ressalva foi resolvida

`run_id=03787204-51a7-49fb-8478-da56a5b07e2a` (competência 2026-06 + 2026-05 + 2026-04, 11 profissionais, 0 falhas). Decisão do usuário: **aceito com ressalvas**, ressalva = investigar o resíduo não-decomposto de Douglas/Danilo. Investigada e encerrada (`.planning/debug/resolved/residuo-delta-douglas-danilo.md`).

A flag `metrics.performance_company_first_score` **continua `false`** e o GATE MPP-04 continua `reprovado`. Esta fase constrói persistência; **não** liga flag nenhuma.

Número que importa para dimensionar o risco desta milestone: com o método antigo, 8 de 11 profissionais recebiam bônus; com o método novo, **1 de 11**. A severidade NÃO vem de bug — vem da mudança de régua-por-empresa. Está medido e registrado.

## 2. O cálculo de faturamento MUDOU em 31/07 (fora da milestone)

`DesempenhoScoreService::computeVarFaturamento()` agora agrega por `Collection::median()`, não `avg()`. Chave de cache foi de `desempenho.compute.v14` para **`v15`**.

Motivo: uma empresa com faturamento quase-zero no mês-base (Lojão do Bras, cid 332: R$ 79,98 → R$ 16.666) fazia a Adman devolver `.diff` de +20.738%, e a média deixava isso dominar a carteira inteira. Carteira do Douglas: **−2,3% real** virava **+766,25%** na nota.

**`computeVarMargem()` continua com `avg()` de propósito.** Simulação provou que mediana na margem levaria o bônus de 6 para 1 em 10 — distribuição diferente. Decisão registrada em `.planning/todos/pending/margem-regua-decisao-2026-08-03.md`. **Não "uniformizar" as duas agregações nesta fase.**

## 3. O snapshot de 2026-06 foi RECONSOLIDADO duas vezes

- 31/07 19:52 — por mim, aplicando a mediana.
- 01/08 09:52 — por outra execução (agendada ou manual), que manteve os valores de faturamento e mexeu na margem.

Efeito acumulado em pagamento: Douglas e Nathalia perderam o bônus; Danilo caiu duas faixas (`intermediario` → `basico` → `sem_bonus`); Stefani caiu para `basico`. De 8 para 5 beneficiários em 11.

**Implicação direta para o critério 3 desta fase:** os comandos que gravam linhas por empresa (`ConsolidarMesDesempenho`, `SnapshotDesempenhoScores`, `WarmDesempenhoCache`) precisam ser **idempotentes e seguros a re-execução**, porque na prática eles rodam mais de uma vez sobre a mesma competência — inclusive por caminhos diferentes no mesmo dia.

## 4. A régua de margem é frágil na fronteira, e isso NÃO será corrigido

Decisão explícita do usuário em 2026-08-03: *"Não vou modificar a régua."*

Consequência observada: Danilo estava a **0,24 pp** da fronteira de 4% da régua de margem; a releitura da mesma competência fechada 14 horas depois deu 2,52% em vez de 4,24%, derrubando de 5 para 4 pontos e tirando o bônus dele. Gustavo oscilou **7,8 pp** na mesma competência.

Não é escopo desta fase resolver isso. Mas o critério 4 (`margem_amostra` contando cobertura de `margem_var_pp`) fica mais valioso justamente por causa disso — é a instrumentação que torna essa fragilidade visível.

## 5. Disciplina do FIXMARG-03 (critério 5) — já exercitada nesta sessão

O gate recusa persistir quando a cobertura de margem fica abaixo de 0,7 e reporta **apenas uma contagem** no stdout (os nomes só vão para `Log::error`). Na reconsolidação de 2026-06 desta sessão o comando imprimiu `OK: 11 · Falhas: 0 · Degradados: 0`, mas a conferência que valeu foi a reconsulta ao `desempenho_score_snapshots` — que mostrou os 11 `updated_at` novos e os valores corretos.

**Qualquer verificação desta fase que dependa de saída de comando é verificação inválida.** Conferir por consulta ao banco.

## 6. Ativos da Fase 121 que esta fase pode reusar

- Tabelas insert-only `desempenho_comparador_profissionais` e `desempenho_comparador_empresas` (schema por empresa já modelado — bom análogo para `desempenho_company_score_snapshots`).
- `desempenho:comparar-score-empresa --run=<run_id>` reimprime qualquer rodada em ~0,3s sem tocar a Adman — útil para validar a persistência nova contra a medição já feita.
- `reguaFaturamento()` e `reguaMargem()` são **públicos** desde a 121 (D-07).
- O shadow expõe `nota_final_por_empresa` e `score_status_por_empresa` no payload de `compute()` (D-05) — é daí que sai o `empresas_score` do critério 1.

## 7. Armadilhas de ambiente já pagas nesta sessão

- **MariaDB local está parado.** Testes rodam em SQLite. Migration que mexe em enum precisa de branch SQLite; `nullOnDelete` exige coluna `nullable()` (quebrou a Fase 79 no MariaDB); dropar índice usado por FK falha no MariaDB (1553) — adicionar o novo ANTES de dropar o antigo.
- **Nunca `cache:clear` no VPS** (derrubou o site em 2026-07-30). Bump de chave de cache torna o clear desnecessário.
- **Bump de `desempenho.compute.vNN` quebra strings hardcoded** em testes de Phase96/V16/V18/Phase116 — atualizar junto, no mesmo commit.
- **Árvore git compartilhada** com outra sessão e outro dev: `git commit -- <paths>`, nunca `git add -A`.
