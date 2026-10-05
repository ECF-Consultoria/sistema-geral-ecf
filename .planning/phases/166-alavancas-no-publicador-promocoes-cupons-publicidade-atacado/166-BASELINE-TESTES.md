# Fase 166 — Baseline de testes (antes de mexer)

- Data/hora: 2026-10-04 (medida antes de qualquer código da fase)
- `git rev-parse HEAD`: `2a27906819857d7f86be1f5a5ea3bab9af702e6a`
- Branch: `feat/publicador-ml-261001`
- Autoloader conferido: `C:\tmp\ecf-publicador-spec-261001\app\Models\PubRascunho.php` (carrega ESTE worktree)
- `node_modules` presente no worktree; `public/build/manifest.json` com data de modificação `2026-10-04 20:22` (o gate final compara para provar que o build rodou de verdade)
- Rodado um grupo por vez, saída redirecionada para arquivo (sem pipe), `echo exit=$?` logo depois.
- `tests/Feature/Publicador` e `tests/Unit/Publicador` rodados em separado (nesta fase são dois comandos).

| # | Grupo (comando) | Testes | Asserções | Falhas | Erros | Pulados | Exit | Tempo | Depois (166-16) |
|---|---|---|---|---|---|---|---|---|---|
| 1 | `tests/Feature/Publicador` | 238 | 1473 | 0 | 0 | 0 | 0 | 1 min 38 s | 550 / 3157 / 0 / 0 / 0 — exit 0 — 1 min 33 s |
| 2 | `tests/Unit/Publicador` | 156 | 522 | 0 | 0 | 0 | 0 | 3 s | 243 / 815 / 0 / 0 / 0 — exit 0 — 8 s |
| 3 | `npm run test:js` (node --test) | 710 | n/d | 2 | 0 | 0 | 1 | 2 s | 900 / n/d / 2 / 0 / 0 — exit 1 — 1,6 s (as 2 do baseline, nenhuma nova) |
| 4 | `tests/Unit/Publicador/Alavancas` + `tests/Feature/Publicador/Alavancas` (novo na fase) | — | — | — | — | — | — | — | 399 / 1976 / 0 / 0 / 0 — exit 0 — 49 s |

## Falhas PRÉ-EXISTENTES (não corrigidas aqui)

Grupo 3 (`test:js`), 708 passam e 2 falham — as mesmas duas já deixadas pela Fase 164, sem relação com o Publicador (planilha / fases de Polos):

1. `Características secundárias nasce recolhido (é o grupo que mais infla)` — o fonte do componente de planilha não casa com `/RECOLHIDOS_INICIAIS = \[G_SECUND\]/`.
2. `FASES_TERMINAIS cobre as três fases de saída, com as strings exatas da planilha` — `deepStrictEqual`: o código tem `['Encerrado','Protocolo Churn','Desistência','Churn']` e o teste espera `['Encerrado','Protocolo Churn','Churn']` (tests/js/polosEntrantes.test.js:188).

## Regra de comparação

Gate da fase = cada grupo com contagem de testes maior ou igual à desta tabela e nenhuma falha nova. No grupo 3 as 2 falhas acima são o piso conhecido. Teste que falha só com rede (sem `Http::fake`) roda isolado antes de virar regressão (ex.: `Phase75/PublicarEmpresaNaoAtribuidaTest::test_admin_nao_recebe_403_no_update`, intermitente conhecido da 164).

## Gate final (166-16, 05/10/2026)

- Mesmos comandos do baseline, um grupo por vez, saída em arquivo e `echo exit=$?` logo depois (sem pipe). Branch `feat/publicador-ml-261001`.
- Publicador Feature: 238 -> 550 testes (+312), 1473 -> 3157 asserções, 0 falha. Publicador Unit: 156 -> 243 (+87), 522 -> 815, 0 falha. JS: 710 -> 900 testes; as 2 falhas são exatamente as 2 do baseline (`Características secundárias nasce recolhido` e `FASES_TERMINAIS`); nenhuma falha nova.
- Os 399 testes das Alavancas já estão contidos nos grupos 1 e 2 (a linha 4 só os isola).
- `public/build/manifest.json` com data de modificação de 05/10/2026 07:07 (o do baseline era 04/10 20:22): o build rodou depois do baseline.

## Prova da migration no MariaDB local (learnings §6)

- `.env` do worktree: `DB_CONNECTION=mysql`, `DB_DATABASE=ecf_admin` em 127.0.0.1:3306 (o banco é compartilhado com outras sessões).
- `migrate:status --path=database/migrations/2026_10_05_100000_create_pub_alavanca_escritas_table.php` = Pending; `migrate --path=<a mesma>` criou a tabela em 249 ms, DONE, sem erro 1059/1830 (nomes de índice e FK explícitos e curtos). A migration só faz `Schema::create`: nada de ALTER em tabela existente. Nenhum `migrate` sem `--path`, nenhum rollback/refresh.
- Conferido pelo `information_schema` (o `artisan db:table` quebra neste PHP local por falta da extensão `intl`: problema do ambiente, não da migration). Índices: `PRIMARY(id)`, `pubale_empresa_ix(mlb_empresa_id,id)`, `pubale_company_ix(company_id,id)`, `pubale_conta_ix(conta_chave,id)`, `pubale_lote_ix(lote_uuid)`, `pubale_item_ix(item_id)` e `pubale_user_fk(user_id)` (o do MariaDB para a FK). FKs: `pubale_empresa_fk`, `pubale_company_fk` e `pubale_user_fk`, todas ON DELETE SET NULL. Tabela vazia (0 linhas).
