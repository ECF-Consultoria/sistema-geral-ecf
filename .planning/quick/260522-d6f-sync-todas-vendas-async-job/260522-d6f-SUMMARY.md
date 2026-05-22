---
phase: 260522-d6f-sync-todas-vendas-async-job
plan: "01"
subsystem: mlb
tags: [job, queue, async, mlb, vendas, adman]
dependency_graph:
  requires: []
  provides: [SyncTodasVendasAdmanJob]
  affects: [MlbController.syncTodasVendasAdman]
tech_stack:
  added: []
  patterns: [Laravel Queue Job, ShouldQueue, Dispatchable]
key_files:
  created:
    - app/Jobs/SyncTodasVendasAdmanJob.php
  modified:
    - app/Http/Controllers/MlbController.php
decisions:
  - "tries=1 no Job: sync de vendas é idempotente por publicação; re-rodar o loop inteiro em falha global desperdiça chamadas API"
  - "timeout=0 no Job: loop de ~17 empresas * até 120s + delays de 600ms excede qualquer timeout razoável de job"
  - "extrairMlbsVendidos copiado (não movido) para o Job: mantém o controller sem dependência inversa e preserva os 3 call-sites existentes no controller (linhas 742, 1689, 1969)"
metrics:
  duration: "~10 min"
  completed: "2026-05-22"
  tasks_completed: 2
  files_modified: 2
---

# Phase 260522-d6f Plan 01: Sync Todas Vendas Async Job Summary

**One-liner:** Loop síncrono de syncTodasVendasAdman (~17 empresas * 120s) movido para SyncTodasVendasAdmanJob, eliminando 504 do nginx — controller retorna flash em <1s.

## Tasks Completed

| # | Task | Commit | Files |
|---|------|--------|-------|
| 1 | Criar SyncTodasVendasAdmanJob | 97f8062 | app/Jobs/SyncTodasVendasAdmanJob.php (novo, 166 linhas) |
| 2 | Refatorar syncTodasVendasAdman | e20a94e | app/Http/Controllers/MlbController.php (+10/-47 linhas) |

## Artifact: app/Jobs/SyncTodasVendasAdmanJob.php

Criado do zero. Implementa `ShouldQueue` com traits padrão do projeto. Propriedades:

- `$tries = 1` — falha única com log; não re-executa loop inteiro
- `$timeout = 0` — sem limite de tempo no nível do job

Construtor: `string $dateFrom, string $dateTo, ?int $userId = null` (promoted readonly, não serializa User nem Request).

`handle(AdmanService $adman)`: replica fielmente o loop das linhas 1734-1763 do controller original — `fetchPerformance`, `extrairMlbsVendidos`, update em `Publicacao` com `GREATEST(COALESCE(...))`, `usleep(600_000)`, acumulação de `$totais`, e logs de início e conclusão com tag `[MLB SyncTodasVendas]`.

`failed(\Throwable $e)`: loga `[MLB SyncTodasVendas] Falha definitiva do job: ...`.

`extrairMlbsVendidos(array $items): array`: cópia local do helper privado do controller — normaliza IDs MLB, extrai qty/preco/netBilling, acumula por MLB, loga items sem ID com tag `[MLB SyncVendas]` (comportamento original preservado).

## Alterações em MlbController.php

**Linhas alteradas (antes → depois):**

- Adicionada linha 5: `use App\Jobs\SyncTodasVendasAdmanJob;`
- Método `syncTodasVendasAdman` (linhas 1715-1774 antes → 1715-1737 depois):
  - Removido: `set_time_limit(0)`, `ini_set('memory_limit', '256M')`
  - Removido: instanciação de `AdmanService`, loop `foreach`, acumulação de `$totais`, `$msg` formatada
  - Adicionado: `MlbEmpresa::...->count()` (contagem leve), `SyncTodasVendasAdmanJob::dispatch(...)`, `return back()->with('success', ...)`
  - Resultado: método passa de ~60 linhas para ~20 linhas

**Intocados (confirmado):**
- `syncVendasAdman` (sync individual por empresa, linha ~1663) — inalterado
- `extrairMlbsVendidos` (helper privado, linha ~1969) — inalterado; ainda usado em linhas 742, 1689, 1969
- `routes/web.php` — não tocado
- `resources/js/Pages/Mlb/Empresas.jsx` — não tocado

## Verificação de Lint

```
php -l app/Jobs/SyncTodasVendasAdmanJob.php
→ No syntax errors detected

php -l app/Http/Controllers/MlbController.php
→ No syntax errors detected
```

## Como Testar Localmente

```bash
# 1. Iniciar o queue worker
php artisan queue:work

# 2. No painel, logar como gestor MLB e abrir modal "Sync Vendas" (todas as empresas)
# 3. Submeter com date_from/date_to válidos
# 4. Confirmar resposta em <1s com flash "Sync de vendas iniciado em background para N empresa(s)..."

# 5. Verificar job na tabela (enquanto pendente):
# SELECT * FROM jobs WHERE payload LIKE '%SyncTodasVendasAdmanJob%' LIMIT 1;

# 6. Acompanhar processamento:
tail -f storage/logs/laravel.log | grep "MLB SyncTodasVendas"
# Deve aparecer: [MLB SyncTodasVendas] Iniciando sync para N empresa(s)...
# Depois:        [MLB SyncTodasVendas] Concluido: X itens, Y com venda, Z publicacoes...
```

## Deviations from Plan

None — plano executado exatamente como especificado.

## Known Stubs

None.

## Threat Flags

None — sem novos endpoints, sem novas superfícies de auth. O Job só é despachado dentro de `syncTodasVendasAdman` que já tem `checkPubAccess('vendas')` + `checkPubRole(['gestor'])`.

## Self-Check: PASSED

- [x] `app/Jobs/SyncTodasVendasAdmanJob.php` existe
- [x] `app/Http/Controllers/MlbController.php` modificado
- [x] Commit 97f8062 existe (Task 1)
- [x] Commit e20a94e existe (Task 2)
- [x] `syncVendasAdman` individual intocado
- [x] `extrairMlbsVendidos` privado intocado no controller
- [x] `routes/web.php` e `Empresas.jsx` não modificados
