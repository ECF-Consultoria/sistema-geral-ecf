---
phase: quick-260522-dx3
plan: 01
subsystem: mlb-sync / dev-panel
tags: [observability, mlb, sync-vendas, dev-panel, queue-job]
key-files:
  created:
    - database/migrations/2026_05_22_100001_create_mlb_sync_vendas_logs_table.php
    - app/Models/MlbSyncVendasLog.php
  modified:
    - app/Jobs/SyncTodasVendasAdmanJob.php
    - app/Http/Controllers/DevController.php
    - resources/js/Pages/Dev/Desenvolvimento.jsx
decisions:
  - Propriedade `$logId` como `private ?int` na classe (não no construtor) para não quebrar dispatch existente
  - DiffBadge estendido com variant 'erros' em vez de criar componente separado
  - Coluna `date_from/date_to` como string para manter formato YYYY-MM-DD exato do request validate
metrics:
  duration: ~20 min
  completed: "2026-05-22"
  tasks: 4
  files: 5
---

# Quick Task 260522-dx3: Log Sync Vendas MLB — Painel Dev

**One-liner:** Instrumenta SyncTodasVendasAdmanJob com tabela mlb_sync_vendas_logs e exibe histórico expansível em /dev/desenvolvimento.

## Commits

| Task | Hash | Descrição |
|------|------|-----------|
| 1 | 03f8179 | feat(quick-260522-dx3-01): migration + model MlbSyncVendasLog |
| 2 | 33dbd0c | feat(quick-260522-dx3-02): instrumenta SyncTodasVendasAdmanJob com log de execução |
| 3 | 25438f6 | feat(quick-260522-dx3-03): DevController passa prop syncVendasLogs |
| 4 | 45334ae | feat(quick-260522-dx3-04): card Sync de Vendas MLB no painel /dev/desenvolvimento |

## Tarefa 1 — Migration + Model

**Migration:** `2026_05_22_100001_create_mlb_sync_vendas_logs_table.php`
- 13 colunas: id, user_id (FK nullable), date_from, date_to, status (enum), total_empresas, total_itens, com_venda, encontradas, erros, empresas_com_erro (json), started_at, finished_at, timestamps
- Index em `started_at` para ordenação eficiente
- Migration executada com sucesso: `243.69ms DONE`

**Model:** `app/Models/MlbSyncVendasLog.php`
- Constantes: `STATUS_RUNNING`, `STATUS_COMPLETED`, `STATUS_FAILED`
- Casts: `empresas_com_erro => array`, `started_at/finished_at => datetime`
- Sem `LogsActivity` (log técnico, não entidade de negócio)

## Tarefa 2 — Job Instrumentado

**Linhas adicionadas ao SyncTodasVendasAdmanJob:** +44 linhas (de 99 → 166 linhas no arquivo)

**Fluxo de log:**
1. `MlbSyncVendasLog::create([status=running, started_at=now()])` — antes da query de empresas
2. `$log->update(['total_empresas' => ...])` — após saber quantas empresas serão processadas
3. `$empresasComErro[] = [...]` — no catch de cada empresa (além do Log::error já existente)
4. `$log->update([status=completed, finished_at=now(), totais...])` — ao fim do handle()
5. `MlbSyncVendasLog::where('id', $this->logId)->update([status=failed])` — no failed()

**Construtor: inalterado** — dispatch em MlbController::syncTodasVendasAdman não precisou ser alterado.

Ocorrências de `MlbSyncVendasLog` no arquivo: **6** (use + create + logId assign + update total_empresas + update completed + update failed).

## Tarefa 3 — Controller

**Linhas adicionadas ao DevController:** +24 linhas (de 42 → 66 linhas)

```php
// Adicionado:
use App\Models\MlbSyncVendasLog;

$syncVendasLogs = MlbSyncVendasLog::orderByDesc('started_at')
    ->limit(10)
    ->get()
    ->map(fn(MlbSyncVendasLog $l) => [...12 campos...]);

// Render estendido com nova prop:
return Inertia::render('Dev/Desenvolvimento', [
    'empresas'       => $empresas,
    'syncVendasLogs' => $syncVendasLogs,
]);
```

## Tarefa 4 — Frontend React

**Novos componentes em Desenvolvimento.jsx:**

| Componente | Responsabilidade |
|-----------|-----------------|
| `SyncVendasStatusBadge` | Badge colored por status: amarelo pulsando (running), verde (completed), vermelho (failed) |
| `SyncVendasLogRow` | Linha clicável com período, horário, duração, 4 badges de totais e badge de status |
| `SyncVendasLogAccordion` | Painel expandido listando empresas com erro (nome + motivo) |
| `SyncVendasLogSection` | Wrapper com estado de accordion; placeholder amigável quando vazio |

**Helpers novos:**
- `fmtDuracao(iniciado, terminado)` — duração em formato compacto (ex.: 2m 14s)
- `fmtData(iso)` — converte YYYY-MM-DD para dd/MM

**DiffBadge:** estendido com variant `erros` (bg-red-500/10 text-red-400 border-red-500/20)

**Posição do novo card:** entre `<DevCard icon={Activity} title="Sync Adman">` e o placeholder "Outros projetos em desenvolvimento".

**npm run build:**
```
✓ built in 12.69s
app-6v54YEGT.js  348.72 kB │ gzip: 116.71 kB
```

## Deviations from Plan

None — plano executado exatamente como escrito.

## Self-Check: PASSED

- [x] `database/migrations/2026_05_22_100001_create_mlb_sync_vendas_logs_table.php` — existe
- [x] `app/Models/MlbSyncVendasLog.php` — existe
- [x] Migration rodou: `2026_05_22_100001_create_mlb_sync_vendas_logs_table ... DONE`
- [x] `app/Jobs/SyncTodasVendasAdmanJob.php` — sintaxe OK, 6 ocorrências de MlbSyncVendasLog
- [x] `app/Http/Controllers/DevController.php` — sintaxe OK, 2 ocorrências de syncVendasLogs
- [x] `resources/js/Pages/Dev/Desenvolvimento.jsx` — 5 ocorrências dos novos identificadores
- [x] `npm run build` — concluído sem erros
- [x] Construtor do Job: inalterado (dispatch em MlbController não afetado)
- [x] Commits: 03f8179, 33dbd0c, 25438f6, 45334ae
