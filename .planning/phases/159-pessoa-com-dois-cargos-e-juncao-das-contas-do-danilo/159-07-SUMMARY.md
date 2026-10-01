---
phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo
plan: 07
status: complete
completed: 2026-10-01
requirements: [SC1, SC2, SC3, SC4, SC5, SC6, D-01, D-08, D-09, D-11, D-12]
---

# 159-07 — Regressão final, deploy e medições de produção

## Resultado

- **Task 1** (`c7a58b3b`): regressão final sem regressão real — `tests/Feature/Phase159/` 109/109;
  `tests/Feature/Phase119/` com as mesmas 17 falhas nominais da baseline; demais falhas provadas
  pré-existentes contra origin/main puro (`deferred-items.md`); motor de nota com diff vazio.
  Rebase sobre origin/main `d01ef00b` limpo antes.
- **Task 2 — checkpoint de decisão:** pergunta apresentada ao usuário em 2026-10-01 (leituras em
  produção, push, `deploy.sh` com as 2 migrations da fase, medições só de leitura). Resposta
  literal: **"Autorizar"**.
- **Task 3:** leitura pré-deploy (VPS em `d01ef00b`, índice `user_setores_user_id_setor_id_unique`
  com o nome esperado, 0 duplicidades), push `d01ef00b..c7a58b3b`, `deploy.sh` exit 0 e — o que
  vale — estado remoto conferido: HEAD `c7a58b3b`, as 2 migrations Ran (batch 154), SHOW INDEX com
  o unique de 3 colunas presente e o de 2 ausente, FK `user_id` apoiada, comandos novos listados,
  workers RUNNING, `/login` 200. Medições registradas em `159-REGRESSAO-FINAL.md` §Produção.

## Decisões / achados para o 159-08

1. **A junção está BLOQUEADA pela trava CR-02**: 2026-08 sem snapshot mensal para o user 15. A
   trava faz o que deve — mover a carteira hoje recalcularia agosto.
2. **Causa do bloqueio é pré-existente e maior que a fase:** o unique legado de
   `desempenho_score_snapshots` (learnings §10.1) continua em produção; o `consolidar-mes` falha com
   1062 para julho, agosto (rodada de hoje 09:56) e vai falhar em setembro (31/10 14:00). Snapshots
   mensais: 2026-06 = 10, 2026-07 = 1, 2026-08 = 1. A correção existe só como arquivo não commitado
   no checkout principal.
3. **D-09:** o ramo legado é marginal (2026-08: 1 nota legado no total, sem divergência). 2026-09
   inconclusivo até o fim da coleta (31/10).
4. **D-08:** ninguém em produção tem hoje o par analista+estrategista; a regra nova não muda o menu
   de ninguém.

## Desvios

- Medições rodadas por script PHP com bootstrap do Laravel (não `tinker --execute`), gravado com
  Write e executado por `plink -m` — mesma leitura, sem problema de aspas.
- Nenhuma escrita de dado em produção além das 2 migrations.
