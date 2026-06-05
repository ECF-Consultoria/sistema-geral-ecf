---
phase: 20-integra-o-ecf-drive-substitui-sync-sftp-do-ml-por-api-http-d
plan: 01
subsystem: grants
status: parcial  # W4 pendente — checkpoint humano blocking (deploy + smoke)
tags: [ecf-drive, grants, api-http, migration, testes]
dependency_graph:
  requires: []
  provides: [EcfDriveService, grants:sync-ecf, segmento-column, ui-ecf-drive-label]
  affects: [company_grants, GrantController, Grants/Index.jsx, routes/console.php]
tech_stack:
  added: []
  patterns: [Http::fake-tests, updateOrCreate-upsert, CASE-WHEN-sqlite-compat, retry-no-throw]
key_files:
  created:
    - app/Services/EcfDriveService.php
    - app/Console/Commands/SyncGrantsFromEcfDrive.php
    - database/migrations/2026_06_05_120000_add_segmento_to_company_grants.php
    - tests/Feature/Phase20/EcfDriveServiceTest.php
    - tests/Feature/Phase20/SyncGrantsFromEcfDriveTest.php
    - tests/Feature/Phase20/CompanyGrantSegmentoTest.php
  modified:
    - config/services.php
    - .env.example
    - app/Providers/AppServiceProvider.php
    - app/Models/CompanyGrant.php
    - app/Http/Controllers/GrantController.php
    - routes/console.php
    - resources/js/Pages/Grants/Index.jsx
decisions:
  - "D-01: SyncGrantsFromSftp permanece intacto (rollback safety +1 fase)"
  - "D-02: status pending quando grantInicio futuro (preserva 3 estados UI)"
  - "D-03: match por adman_account_id (nao ml_store_id legacy)"
  - "D-04: grantsExpirandoEm cache 5min — UI usa tabela local, nao API live"
  - "D-05: ECF_WEBHOOK_SECRET vazio ate Phase 21"
  - "D-06: coluna segmento truncate + tooltip nativo HTML"
metrics:
  duration: "~45 min"
  completed: "2026-06-05"
  tasks_completed: 9
  tasks_total: 9
  files_created: 6
  files_modified: 7
---

# Phase 20 Plan 01: Integração ECF Drive — Summary (PARCIAL)

> Substitui SFTP+XLSX por wrapper HTTP `EcfDriveService` consumindo API REST do ECF Drive; adiciona coluna `segmento` e label "via ECF Drive" na UI.

**Status:** W1 + W2 + W3 concluídas e commitadas. **W4 aguarda ação humana** (deploy + configuração `ECF_API_KEY` em prod + smoke).

---

## Waves Executadas

### W1 — Backend (4 tasks)

| Task | Arquivo | Commit |
|------|---------|--------|
| W1-T1 | `EcfDriveService.php` + `config/services.php` + `.env.example` + `AppServiceProvider` | `0eaa216` |
| W1-T2 | Migration `segmento` + `CompanyGrant::$fillable` | `689c3f7` |
| W1-T3 | Comando `grants:sync-ecf {--dry-run}` | `2f9fd5e` |
| W1-T4 | Schedule + GrantController (3 sites) + prop `segmento` no index | `fc53113` |

### W2 — Frontend (1 task)

| Task | Arquivo | Commit |
|------|---------|--------|
| W2-T1 | `Grants/Index.jsx` — coluna Segmento + label "via ECF Drive" | `70c9da6` |

`npm run build`: **0 erros** (build green).

### W3 — Testes (3 tasks)

| Task | Arquivo | Testes | Commit |
|------|---------|--------|--------|
| W3-T1 | `EcfDriveServiceTest.php` | 6 testes (Http::fake) | `432cb57` |
| W3-T2 | `SyncGrantsFromEcfDriveTest.php` | 8 testes (dry-run, match, fallback, órfãos) | `cfa20f5` |
| W3-T3 | `CompanyGrantSegmentoTest.php` | 4 testes (migration, fillable, props) | `c5a331e` |

Resultado final:

```
php artisan test --filter=Phase20
Tests: 18 passed (29 assertions)
```

Não-regressão:

```
php artisan test --filter=Grant
Tests: 15 passed (26 assertions)  — nenhuma regressão
```

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] retry() lançava RequestException antes do successful() ser checado**
- **Encontrado durante:** W3-T1 (EcfDriveServiceTest)
- **Problema:** `->retry(2, 500)` por padrão lança `RequestException` quando todos os retries falham, antes que `$response->successful()` fosse verificado. O teste esperava `\RuntimeException` mas recebia a subclasse com stack trace diferente.
- **Fix:** `->retry(2, 500, null, false)` — 4º parâmetro `throw=false` desativa o throw automático; o service verifica `successful()` e lança `\RuntimeException` manualmente com mensagem clara.
- **Arquivos:** `app/Services/EcfDriveService.php`
- **Commit:** `432cb57`

**2. [Rule 1 - Bug] CNPJ duplicado causava UniqueConstraintViolation no helper makeCompany()**
- **Encontrado durante:** W3-T2 (test_match_por_cust_id_quando_adman_account_id_bate)
- **Problema:** Helper `makeCompany()` usava o mesmo CNPJ padrão para todas as instâncias criadas no mesmo teste. A tabela `companies` tem constraint UNIQUE em `cnpj`.
- **Fix:** Sequência incremental estática (`self::$cnpjSeq++`) gera CNPJ único por chamada no mesmo test run.
- **Arquivos:** `tests/Feature/Phase20/SyncGrantsFromEcfDriveTest.php`
- **Commit:** `cfa20f5`

**3. [Rule 1 - Bug] FIELD() MySQL-specific quebrava GrantController em SQLite (testes)**
- **Encontrado durante:** W3-T3 (test_props_grants_index_incluem_segmento)
- **Problema:** `orderByRaw("FIELD(status,'active','pending','expired')")` é sintaxe MySQL-only; SQLite (ambiente de testes) não a suporta.
- **Fix:** Substituído por `CASE WHEN 'active' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END` — compatível com SQLite e MySQL.
- **Arquivos:** `app/Http/Controllers/GrantController.php`
- **Commit:** `c5a331e`

---

## W4 — Pendente: Gate Humano (blocking)

**Ação necessária antes de colocar em prod:**

1. **REVOGAR** a API key `ecf_c7b9...` que foi colada no chat (R-03 — key vazada no histórico).
2. **GERAR NOVA** key no painel ECF Drive.
3. Configurar `.env` do VPS com `ECF_API_KEY=ecf_<nova-key>`.
4. Executar deploy (`./deploy_parcial.sh` ou `deploy.sh`).
5. `php artisan migrate --force` (aplica migration `segmento`).
6. `php artisan grants:sync-ecf --dry-run` — confirmar grants recebidos.
7. `php artisan grants:sync-ecf` — apply real.
8. Smoke UI em `https://admin.ecfconsultoria.com.br/grants`.

Ver PLAN.md W4-T1 `<how-to-verify>` para os 15 passos completos.

---

## Known Stubs

Nenhum. Todos os campos da UI recebem dados reais do banco pós-sync.

---

## Threat Flags

| Flag | Arquivo | Descrição |
|------|---------|-----------|
| threat_flag: key_leaked | `.env.example` (histórico de chat) | API key `ecf_c7b9...` foi colada pelo usuário no briefing — W4 instrui revogação explícita antes do deploy |

---

## Decisões Registradas

- **D-01:** `SyncGrantsFromSftp.php` mantido intacto no repo — rollback safety por +1 fase. Remoção definitiva em Phase 22+.
- **D-02:** `status='pending'` quando `grantInicio` futuro — preserva semântica de 3 estados na UI sem perder informação.
- **D-03:** Match por `companies.adman_account_id` (não `ml_store_id` legacy) — fonte autoritativa pós-Phase 18.5.
- **D-04:** `grantsExpirandoEm()` cacheado 5min é apenas conveniência; UI usa tabela local (autoritativa).
- **D-05:** `ECF_WEBHOOK_SECRET` mapeado e vazio — Phase 21 implementa webhook real-time com HMAC SHA256.
- **D-06:** Coluna `segmento` com `truncate` + `title` tooltip nativo HTML — sem nova dependência de componente.

---

## Riscos Residuais

- **R-01 (API offline em prod):** Wrapper tem `retry(2, 500, null, false)` + `timeout(15)`. Catch global grava `success=false` no JSON de status. UI exibe erro sem corromper tabela.
- **R-02 (cust_id ambíguo):** Log `MULTIMATCH ids=[...]` no grants-orfaos.log. Nenhuma empresa atualizada automaticamente nesse cenário.
- **R-03 (key vazada):** **Pendente W4** — revogar `ecf_c7b9...` e gerar nova antes do deploy.
- **R-04 (rate limit desconhecido):** Schedule 1×/dia alinhado ao SFTP anterior. Retry absorve 429 transitório.
- **R-05 (segmento quebrar testes):** Migration additive + $fillable com `segmento` — não-regressão confirmada (suite Grant verde).

---

## Próxima Fase

**Phase 21** implementa webhook real-time `grant.expirando` via `ECF_WEBHOOK_SECRET` (já mapeado no `.env`). Contrato: HMAC SHA256 no header `X-Webhook-Signature`, endpoint novo em `routes/web.php`.

`SyncGrantsFromSftp.php` segue no repo como rollback safety até Phase 22 (ou após 2 semanas de operação estável da API ECF Drive em prod).

---

## Self-Check: PENDENTE W4

Verificações locais passaram:
- `php artisan test --filter=Phase20`: **18 passing**
- `php artisan test --filter=Grant`: **15 passing (não-regressão)**
- `php artisan list | grep grants:sync`: ambos os comandos visíveis
- `php artisan schedule:list | grep grants`: `sync-ml-grants-ecf` registrado
- `Schema::hasColumn('company_grants', 'segmento')`: `true` (local)
- `npm run build`: **0 erros**

Verificações de prod: **Pendente W4** (gate humano).
