# Itens fora de escopo encontrados durante a execução — Fase 137

## `tests/Feature/FechamentoMigrationTest.php` falha desde antes desta fase

**Encontrado durante:** Plano 137-02, Tarefa 1 (verificação do gate).

`Tests\Feature\FechamentoMigrationTest::test_migration_adiciona_colunas` falha com
`Failed asserting that false is true` ao checar `Schema::hasColumn('companies', 'service_type')`
(e `contract_start`, `contract_end`, `additional_service`).

**Confirmado que NÃO é regressão desta fase:** removi temporariamente as 6 migrations novas
(as do plano 137-01 e as do 137-02) da pasta `database/migrations/` e rodei o teste isolado — a
falha persistiu igual, sem nenhuma migration nova presente. Arquivos restaurados em seguida, sem
perda.

Não investiguei a causa raiz (fora do escopo do plano 02: nenhuma das colunas testadas
pertence a `fechamento_snapshots`, `fechamento_grupo_snapshots` ou `fechamento_reconsolidacoes`).
Não faz parte do filtro de gate da fase (`Phase122|Phase136|Phase137`).
