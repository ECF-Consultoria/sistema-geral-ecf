---
quick_id: 260609-mom
slug: cust-id-accessor-priority
type: summary
mode: quick
status: complete
created: 2026-06-09
completed: 2026-06-09
commits:
  - 4f3cf3d
files_modified:
  - app/Models/Company.php
  - app/Services/AdmanService.php
  - app/Console/Commands/DiagnoseCustId.php
  - app/Jobs/RefreshGrossBillingCacheJob.php
  - app/Http/Controllers/AdminController.php
  - app/Http/Controllers/CompanyController.php
  - app/Http/Controllers/DashboardController.php
description: Inverteu prioridade do accessor Company::cust_id de ml_store_id ?: adman_account_id para adman_account_id ?: ml_store_id; alinhou call-sites diretos em AdmanService que bypassam o accessor; atualizou comentários nos consumidores
---

# Quick Task 260609-mom — Inverte prioridade do accessor cust_id

## Resumo do fix

**Causa raiz** (validada em prod 2026-06-09 via teste direto na Adman API):
- ADHARAPRINTSHOP (id=189): `custId=462789629` (adman_account_id) → OK R$3.882,86 / 420 items; `custId=3392427323` (ml_store_id) → HTTP 500.
- Adman API espera o `adman_account_id`. O `ml_store_id` historicamente "funcionava" porque a Adman trata `seller_id` do ML como ID interno para contas Meli — mas só para 167 das 170 empresas onde os dois IDs coincidem. Para as 2 empresas onde divergem (ADHARA #189, AVF_2K #243), a Adman rejeita o ml_store_id.

**Decisão**: inverter a ordem no accessor `Company::getCustIdAttribute()` de `ml_store_id ?: adman_account_id` para `adman_account_id ?: ml_store_id`. Fallback continua atendendo as 3 empresas ml-only.

**Impacto**:
- Dashboards (admin/user), Fechamento, Sugadores e sync usam o mesmo accessor `$company->cust_id` → todos passam a chamar a Adman com o ID Adman primeiro.
- Empresas onde os dois IDs coincidem (99%) não mudam nada.
- ADHARA e AVF_2K passam a ser sincronizadas com sucesso na próxima execução.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] AdmanService::syncCompany e syncMonthRevenue bypassavam o accessor**

- **Encontrado durante:** Verify 2 do PLAN.md.
- **Issue:** `app/Services/AdmanService.php` linhas 87 e 697 pré-resolviam `$company->ml_store_id ?: $company->adman_account_id` localmente, sem usar o accessor. Esses são os dois pontos centrais que efetivamente chamam a Adman API (sync diário e sync mensal de revenue). Deixá-los inalterados manteria o bug intacto exatamente nos caminhos críticos.
- **Fix:** invertida a ordem para `$company->adman_account_id ?: $company->ml_store_id` nas duas linhas + comentário curto referenciando o accessor.
- **Arquivos modificados:** `app/Services/AdmanService.php` (linhas 87→88 e 697→699 com comentário extra).
- **Commit:** `4f3cf3d`.

**2. [Rule 1 - Bug] DiagnoseCustId.php documentava o accessor antigo e invariante do --fix invertido**

- **Encontrado durante:** Verify 2 (match em comentário PHPDoc).
- **Issue:** O comentário do command descrevia `getCustIdAttribute()` retornando `ml_store_id ?: adman_account_id` (agora incorreto) e usava esse retorno para justificar o filtro `--fix` que limpa `adman_account_id` quando há `mlToken` ativo. Com a inversão, o invariante mudou: limpar `adman_account_id` agora derruba `cust_id` para `ml_store_id`, que é exatamente o ID que a Adman rejeita.
- **Fix:** atualizada a descrição do accessor e adicionada nota `ATENCAO` flagging que a lógica do `--fix` precisa ser revisitada antes do próximo uso. **Nenhuma mudança de código** — só comentário.
- **Arquivos modificados:** `app/Console/Commands/DiagnoseCustId.php` (linhas 33-43).
- **Commit:** `4f3cf3d`.

### Decisões deliberadas

**3. `SugadorAnalysisService.php` linha 97 — comentário não alterado**

- O comentário diz "Antes usava `ml_store_id ?: adman_account_id`, … preferir o ID Adman (adman_account_id)". A forma histórica continua válida e agora reforça a decisão tomada na quick task. Per constraint do PLAN.md: "só atualizar se a nova ordem invalidar o texto" — não invalida.

## Arquivos cujos comentários foram atualizados (Mudança 3)

| Arquivo                                          | Trecho                                                                |
| ------------------------------------------------ | --------------------------------------------------------------------- |
| `app/Jobs/RefreshGrossBillingCacheJob.php`       | linha 93 — `Company::cust_id (adman_account_id ?: ml_store_id)`       |
| `app/Http/Controllers/AdminController.php`       | linha 187 — `Company::cust_id (adman_account_id ?: ml_store_id)`      |
| `app/Http/Controllers/AdminController.php`       | linha 482 — `accessor cust_id (adman_account_id ?: ml_store_id)`      |
| `app/Http/Controllers/AdminController.php`       | linha 648 — `cust_id (adman_account_id ?: ml_store_id)`               |
| `app/Http/Controllers/CompanyController.php`     | linha 155 — `accessor cust_id (adman_account_id ?: ml_store_id)`      |
| `app/Http/Controllers/DashboardController.php`   | linha 637 — `cust_id = adman_account_id ?: ml_store_id`               |

(A linha 190 do `AdminController.php` mencionada no PLAN está dentro do mesmo bloco de comentário que linha 187 — atualizada na mesma operação. A linha 97 do `SugadorAnalysisService.php` permanece como histórico não invalidado.)

## Verifies (saída final)

```text
=== VERIFY 1: nova ordem no accessor ===
64:        $custId = $this->adman_account_id ?: $this->ml_store_id;

=== VERIFY 2: ordem antiga em código ===
(zero matches — OK)

=== VERIFY 3: semântica de null preservada ===
65:        return $custId !== '' ? $custId : null;

=== EXTRA: confirmar ordem nova em AdmanService ===
88:        $custId = $company->adman_account_id ?: $company->ml_store_id;
699:        $custId = $company->adman_account_id ?: $company->ml_store_id;
```

## Follow-ups (não escopo desta quick)

- **Orquestrador**: deploy + cache flush para empresas #189 (ADHARA) e #243 (AVF_2K) pós-merge — esse passo está fora do escopo do executor.
- **DiagnoseCustId --fix**: invariante mudou — revisitar a lógica antes do próximo uso (anotado no PHPDoc do command).

## Self-Check

- [x] `app/Models/Company.php` modificado — `FOUND`
- [x] `app/Services/AdmanService.php` modificado — `FOUND`
- [x] `app/Console/Commands/DiagnoseCustId.php` modificado — `FOUND`
- [x] `app/Jobs/RefreshGrossBillingCacheJob.php` modificado — `FOUND`
- [x] `app/Http/Controllers/AdminController.php` modificado — `FOUND`
- [x] `app/Http/Controllers/CompanyController.php` modificado — `FOUND`
- [x] `app/Http/Controllers/DashboardController.php` modificado — `FOUND`
- [x] Commit `4f3cf3d` existe — `FOUND`
- [x] Verify 1 PASS
- [x] Verify 2 PASS (zero matches)
- [x] Verify 3 PASS

## Self-Check: PASSED
