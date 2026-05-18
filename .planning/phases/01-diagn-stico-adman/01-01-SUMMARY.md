# Plan 01-01 Summary — Foundation: AdmanSyncLog + 5 RED Tests

**Executed:** 2026-05-18
**Status:** COMPLETE — migration applied, 5 RED tests confirmed, ProfileTest GREEN

---

## Verification Results

### Migration Applied

```
INFO  Running migrations.
2026_05_18_100001_create_adman_sync_logs_table ....... 261.81ms DONE
```

Tabela `adman_sync_logs` criada com sucesso em produção (MySQL via XAMPP).

### DevControllerTest — 5 RED (esperado)

```
FFFFF                                                    5 / 5 (100%)

There were 5 failures:

1) test_index_retorna_empresas_com_synced_at
   Property [empresas] does not exist.

2) test_index_retorna_raw_data_no_payload
   Property [empresas.0.raw_data.grossBilling.value] does not exist.

3) test_index_retorna_diff_do_ultimo_log
   Property [empresas.0.criados] does not exist.

4) test_dispatch_sync_enfileira_job
   Expected response status code [201, 301, 302, 303, 307, 308] but received 404.

5) test_dispatch_sync_rejeita_nao_admin
   Expected response status code [403] but received 404.
```

**Razão correta das falhas:** DevController e rotas não existem ainda. Os testes 1-3 falham porque a rota GET retorna a page existente sem a prop `empresas`. Os testes 4-5 falham com 404 porque a rota POST não existe.

### ProfileTest — 5 GREEN (zero regressão)

```
.....                                                    5 / 5 (100%)
OK (5 tests, 22 assertions)
```

---

## Decisões tomadas

### Por que Company::create() em vez de factory?

O projeto não possui `CompanyFactory` — apenas `UserFactory` existe em `database/factories/`. O `Company::create([...])` com campos obrigatórios é a alternativa correta conforme documentado em `01-PATTERNS.md` (nota 5 da seção "Notas de implementação críticas").

### Correção de migrations com MODIFY COLUMN (SQLite)

Quatro migrations existentes usavam `DB::statement("ALTER TABLE ... MODIFY COLUMN ...")`, sintaxe exclusiva do MySQL que falha no SQLite in-memory usado nos testes. As migrations foram corrigidas para envolver os comandos MySQL em `if (DB::getDriverName() !== 'sqlite')`:

- `2026_04_28_000001_add_value_type_and_new_metrics_to_goals_table.php`
- `2026_04_30_000004_add_analista_to_publication_role.php`
- `2026_05_07_000005_add_analista_to_company_users_role_enum.php`
- `2026_05_15_000001_refactor_sugadores_adgroup_first.php`

Esta é uma correção de infraestrutura de testes pré-existente, não relacionada à funcionalidade do plano.

### Correção de APP_URL em phpunit.xml

O `.env` tinha `APP_URL=http://localhost/ecf_admin/public`, fazendo todos os testes resolverem rotas como `/ecf_admin/public/profile` em vez de `/profile`. Adicionado `<env name="APP_URL" value="http://localhost"/>` ao `phpunit.xml`.

### Correção de ProfileTest com SoftDeletes

O `test_user_can_delete_their_account` usava `$this->assertNull($user->fresh())`, mas `User` tem `SoftDeletes` — soft delete não torna o `fresh()` null. A asserção foi corrigida para verificar `withTrashed()->find()->trashed()`.

### Sem import explícito de AdmanSyncLog em Company.php

Como `AdmanSyncLog` e `Company` estão no mesmo namespace `App\Models`, não é necessário adicionar `use App\Models\AdmanSyncLog` ao `Company.php`. O PHP resolve automaticamente.

---

## Arquivos criados/modificados

| Arquivo | Ação |
|---------|------|
| `database/migrations/2026_05_18_100001_create_adman_sync_logs_table.php` | Criado |
| `app/Models/AdmanSyncLog.php` | Criado |
| `app/Models/Company.php` | Modificado — adicionado `admanSyncLogs()` e `latestAdmanSyncLog()` |
| `tests/Feature/DevControllerTest.php` | Criado — 5 RED tests |
| `phpunit.xml` | Modificado — APP_URL corrigido |
| `tests/Feature/ProfileTest.php` | Modificado — SoftDeletes assertion corrigida |
| `database/migrations/2026_04_28_000001_add_value_type_and_new_metrics_to_goals_table.php` | Modificado — MODIFY COLUMN guardado |
| `database/migrations/2026_04_30_000004_add_analista_to_publication_role.php` | Modificado — MODIFY COLUMN guardado |
| `database/migrations/2026_05_07_000005_add_analista_to_company_users_role_enum.php` | Modificado — MODIFY COLUMN guardado |
| `database/migrations/2026_05_15_000001_refactor_sugadores_adgroup_first.php` | Modificado — MODIFY COLUMN guardado |

---

## Próximo passo

**Plan 01-02:** Implementar `DevController` (index + dispatchSync) + rotas + modificar `AdmanService::syncCompany()` para persistir `AdmanSyncLog`. Os 5 testes devem passar para GREEN após essa fase.
