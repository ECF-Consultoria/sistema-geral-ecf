---
phase: 49-rankings-de-desempenho-por-fun-o-ranking-separado-de-publica
plan: "02"
status: done
executed_at: 2026-06-30
---

# SUMMARY — Plan 49-02: Rota /publicacao/desempenho + sidebar entry

## Resultado do BLOCKER: Verificação de Schema

**Decisão: `publication_role` EXISTE (sem `_legacy`).**

Investigação nas migrations confirmou:
- `2026_04_30_000002_add_publication_role_to_users_table.php` criou a coluna `publication_role` com enum `('gestor','lider','publicador')`
- `2026_04_30_000004_add_analista_to_publication_role.php` adicionou `'analista'` ao enum
- **Não há nenhuma migration que renomeie `publication_role` para `publication_role_legacy`**

A memória `project_legacy_columns_rename.md` refere-se ao renomeo de `cargo` → `setor` (migration `rename_cargo_to_setor`) e à adição das colunas `setor_legacy`/`cargo_legacy` como campos de compatibilidade **separados**. O campo `publication_role_legacy` no `$fillable` do User.php é uma coluna adicional distinta (para retrocompat de migração gradual), **não** a renomeação de `publication_role`.

**Conclusão:** `indexPolos()` linha 138 (`whereIn('publication_role', ['publicador', 'lider'])`) está correto. Nenhuma correção de filtro necessária.

## Desvio Identificado: DATE_FORMAT incompatível com SQLite

Durante execução dos testes (SQLite in-memory), `indexPolos()` linha 222 usava `DATE_FORMAT(data, '%Y-%m')` — função exclusiva do MySQL que não existe no SQLite.

**Fix aplicado** no commit da Tarefa 5: substituição por expressão condicional via `DB::getDriverName()`:
- SQLite: `strftime('%Y-%m', data)`
- MySQL/MariaDB: `DATE_FORMAT(data, '%Y-%m')`

Esse fix não afeta produção (MySQL) e não altera a lógica de negócio. O `index()` (Wave 1) não tem esse problema pois não usa `DATE_FORMAT`.

## Commits

| SHA | Mensagem | Tarefa |
|-----|----------|--------|
| `328c561` | feat(49-02): adiciona método público indexPublicacao() em PerformanceController | T2 Backend |
| `0f2e651` | feat(49-02): adiciona rota GET /publicacao/desempenho -> publicacao.desempenho.index | T3 Rota |
| `1c15408` | feat(49-02): adiciona entry Desempenho no grupo Publicacoes do sidebar | T4 Sidebar |
| `8748d47` | test(49-02): testes de acesso para rota /publicacao/desempenho + fix DATE_FORMAT SQLite em indexPolos() | T5 Testes + fix |

## Resultado dos Testes

```
PASS Tests\Feature\PublicacaoDesempenhoRouteTest
  ✓ admin acessa rota e recebe 200
  ✓ user sem permission recebe 403
  ✓ user com mlb dashboard acessa rota e recebe 200
  ✓ admin renderiza performance index com setor polos

PASS Tests\Feature\PerformanceCargoFilterTest (anti-regressão Wave 1)
  ✓ sem cargo retorna ranking completo
  ✓ cargo analista retorna apenas analistas
  ✓ cargo estrategista retorna apenas estrategistas
  ✓ cargo invalido ignorado retorna ranking completo
  ✓ cargo geral retorna ranking completo
  ✓ prop cargo propagada no inertia

Tests: 10 passed (25 assertions)
```

## Build Frontend

`npm run build` — verde em 12.27s. `AppLayout-BVBPj95p.js` regenerado incluindo a nova entry do sidebar.

## Verificação Final

- `php artisan route:list --name=publicacao.desempenho.index` retorna: `GET|HEAD publicacao/desempenho ......... publicacao.desempenho.index › PerformanceController@indexPublicacao`
- `indexPolos()` permanece `private` — não aparece no router
- `index()` (Wave 1) não foi tocado

## Success Criteria

- [x] Schema verificado: `publication_role` EXISTE — nenhum fix de filtro necessário
- [x] Método público `indexPublicacao()` criado em PerformanceController (wrapper de `indexPolos()`)
- [x] Rota `GET /publicacao/desempenho` → `publicacao.desempenho.index` com `permission:mlb.dashboard`
- [x] Entry "Desempenho" no grupo Publicações do AppLayout.jsx com icon Trophy e permission mlb.dashboard
- [x] Testes Feature GREEN: admin 200, sem-permission 403, com-permission 200, setor='polos' na prop
- [x] `npm run build` verde
- [x] Suite Wave 1 (49-01) continua GREEN
- [x] `index()` não foi modificado
