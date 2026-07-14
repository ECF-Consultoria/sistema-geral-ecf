# Deferred Items — Phase 75

Descobertas fora do escopo do plano atual, registradas para tratamento futuro.

## Testes legacy pré-existentes quebrados (não causados pela Phase 75)

**Descoberto em:** Plan 75-03 (verificação de regressão `--filter=Comercial`)

- `tests/Feature/Phase13ComercialTest.php` — 10 de 12 casos falham em isolamento.
- `tests/Feature/Phase14ComercialTest.php` — caso `update ignora campos legacy` falha.

**Causa:** Os testes montam o payload de cadastro com o campo legacy `service_type`
(enum antigo), removido no refactor da Phase 14 quando `ComercialController::store()`
passou a exigir `servicos[]`. O `store()` atual retorna `validation.required` em `nome`/
`servicos` para esses payloads antigos. Falham identicamente com ou sem as mudanças da
Phase 75 (o Plan 75-03 apenas adicionou um arquivo de teste novo, sem tocar no controller).

**Ação:** NÃO corrigido nesta phase (fora de escopo — SCOPE BOUNDARY do executor).
Recomendação: atualizar ou aposentar esses testes legacy numa quick task de manutenção
de suíte, alinhando o payload ao contrato atual (`servicos[]`).
