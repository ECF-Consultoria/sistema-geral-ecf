---
id: 260527-cleanup-suites-coexistencia
created: 2026-05-27
priority: medium
effort_estimate: 1h
category: tech-debt
resolves_phase: 14
references:
  - .planning/phases/14-consolida-o-do-modelo-de-servi-os-frente-b/deferred-items.md (Plan 14-06)
  - .planning/phases/14-consolida-o-do-modelo-de-servi-os-frente-b/14-VERIFICATION.md (item 4)
status: pending
---

# Cleanup de suítes antigas de coexistência (pós-drop)

## Contexto

Plan 14-06 dropou as 6 colunas legacy de `companies`. Várias suítes de teste assumiam o estado de COEXISTÊNCIA (legacy populado + contratos populados) e agora falham quando rodadas contra o schema pós-drop:

- **`Phase14MigrationTest`** — reexecuta a migration de dados criando companies com campos legacy depois que o schema já foi dropado.
- **`Phase14VerificarCobrancaTest::test_aborta_com_divergencia`** — depende de divergência no campo antigo; o comando agora vira smoke check pós-drop quando a coluna não existe.
- **`Phase14AdminControllerCobrancaTest`** — alguns cenários golden foram escritos para coexistência.
- **`Phase14ComercialTest::test_update_ignora_campos_legacy`** — perdeu sentido após remover a compat do schema.

A suíte focada pós-drop passa (`Phase14FechamentoUiTest|Phase14BladeRefactorTest|Phase14MlbControllerFiltroTest` — 9/9 verdes / 101 assertions). Mas rodar `php artisan test` completo gera N falhas vermelhas que poluem CI.

## Decisões a tomar

Para cada suíte/teste:

| Suíte/teste | Opção 1 (preferida) | Opção 2 |
|-------------|---------------------|---------|
| `Phase14MigrationTest` | Converter para teste de schema pós-drop (verifica que `down()` recria colunas, que `up()` é no-op se já rodou, e que catálogo `servicos` permanece intacto) | Deletar — migration é histórica e não muda mais |
| `Phase14VerificarCobrancaTest::test_aborta_com_divergencia` | Deletar — comando virou smoke check trivial pós-drop | Marcar `@requires-legacy-schema` e skipar via feature flag |
| Cenários golden de `Phase14AdminControllerCobrancaTest` | Manter os que cobrem `CobrancaCalculator::novo` puro; deletar os que reconstroem array legacy | Idem |
| `Phase14ComercialTest::test_update_ignora_campos_legacy` | Deletar — não há mais campos legacy a serem ignorados | — |

## Critério de sucesso

```bash
php artisan test --filter='Phase14'
# 100% verde (ex: 30+ testes / 200+ assertions)
# Zero teste "skipped" sem razão
# Zero teste vermelho
```

## Tarefas

1. Rodar `php artisan test --filter='Phase14'` e capturar lista exata de falhas vermelhas.
2. Para cada falha, decidir entre Opção 1 (converter) e Opção 2 (deletar). Anotar decisão em commit message.
3. Aplicar mudanças em commits atômicos:
   - `test(14-cleanup): converte Phase14MigrationTest para schema pos-drop`
   - `test(14-cleanup): deleta cenarios golden obsoletos de Phase14AdminControllerCobrancaTest`
   - `test(14-cleanup): deleta Phase14ComercialTest::test_update_ignora_campos_legacy`
   - `test(14-cleanup): deleta Phase14VerificarCobrancaTest::test_aborta_com_divergencia`
4. Rodar suíte combinada Phase 14 — confirmar 100% verde.
5. Atualizar `deferred-items.md` — mover entrada "Suites antigas ainda assumem colunas legacy apos o drop" para "Resolvidos".
6. Mover este TODO para `.planning/todos/completed/`.

## Notas

- Phase13ComercialTest também está obsoleto (registrado em `deferred-items.md` Plan 14-04 separadamente) — pode ser combinado neste cleanup ou deixado pra outra quick task `260527-cleanup-phase13-comercial-test`.
- O comando `phase14:verificar-cobranca` deve ser deletado em quick task separada APÓS confirmar `260527-verificar-cobranca-em-prod` com 0 divergências.
