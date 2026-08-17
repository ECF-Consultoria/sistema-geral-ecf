# Itens fora de escopo — Fase 128

## Plano 04

### `tests/Feature/Phase13ComercialTest.php` — 10 de 12 testes falhando (pré-existente)

Descoberto ao rodar a bateria ampla de regressão do Comercial após o plano 04
(`ComercialController::store()`/`update()` são tocados por muitas fases). **Não é
regressão desta fase**: o arquivo usa o payload legado `service_type` (string única),
substituído por `servicos[]` (catálogo Frente A) desde a Fase 14
(`Servico`/`ContratoServico`). `store()` hoje valida `'servicos' => 'required|array|min:1'`
— um payload sem essa chave falha a validação independente de qualquer mudança do
plano 04.

Confirmado por `git log`: `tests/Feature/Phase13ComercialTest.php` não é tocado desde
`f58da269` (2026-05-25, Fase 13 — criação do arquivo); `ComercialController.php` foi
modificado por dezenas de fases desde então. O arquivo de teste nunca foi atualizado
para o payload novo.

Fora de escopo do plano 04 (nenhuma linha de `store()` relacionada a `nome_contato`
altera esse comportamento — confirmado revertendo mentalmente a mudança: o payload
de `payloadValido()` já falharia em `'servicos' => 'required'` de qualquer forma).
Não corrigido aqui.

### `tests/Feature/Phase14ComercialTest.php::test_update_ignora_campos_legacy` — pré-existente

Falha em `ComercialController::update()` (coluna legacy `service_type`), método que o
plano 04 não tocou (só `store()` foi alterado). Mesma família de teste desatualizado
contra colunas legacy já renomeadas em fases anteriores (ver
`.planning/learnings/` — `project_legacy_columns_rename.md`). Não corrigido aqui.
