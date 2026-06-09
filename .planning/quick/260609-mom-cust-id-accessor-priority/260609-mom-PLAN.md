---
quick_id: 260609-mom
slug: cust-id-accessor-priority
type: plan
mode: quick
created: 2026-06-09
description: Inverte prioridade no accessor Company::cust_id (adman_account_id ?: ml_store_id) para corrigir empresas onde os 2 IDs divergem ficando zeradas no dashboard
files:
  - app/Models/Company.php
  - app/Jobs/RefreshGrossBillingCacheJob.php
  - app/Http/Controllers/AdminController.php
  - app/Http/Controllers/CompanyController.php
  - app/Http/Controllers/DashboardController.php
  - app/Services/SugadorAnalysisService.php
must_haves:
  - Company::getCustIdAttribute usa adman_account_id ?: ml_store_id (não mais ml_store_id ?: adman_account_id)
  - Comentário do accessor reflete a nova lógica e o motivo (Adman API espera adman_account_id; ml é fallback)
  - Comentários dos call-sites atualizados (não muda código deles — só strings nos comentários)
  - Sem touch em código que não seja o accessor + comentários
  - Sem mexer em testes (não há cobertura existente que dependa dessa ordem específica)
---

# Quick Task 260609-mom — Inverte prioridade do accessor cust_id

## Contexto

**Bug**: empresas onde `adman_account_id` e `ml_store_id` divergem ficam zeradas no dashboard a partir do dia em que a Adman API começa a rejeitar o `ml_store_id`.

**Causa raiz** (confirmada via teste direto na Adman API em prod, 2026-06-09):
- Para ADHARAPRINTSHOP (id=189):
  - `custId=462789629` (adman_account_id) → **OK** R$3.882,86 / 420 items
  - `custId=3392427323` (ml_store_id)     → **HTTP 500**
- Adman API espera o `adman_account_id`. O `ml_store_id` historicamente "funcionava" porque a Adman trata `seller_id` do ML como ID interno para contas Meli — mas só para empresas onde adman==ml (167 das 170 hoje). Para as 2 empresas onde diverge (#189 ADHARA, #243 AVF_2K) a Adman não aceita o ml_store_id.

**Decisão**: inverter prioridade no accessor único `Company::getCustIdAttribute()`.

## Tarefa única

### T-01 — Inverter accessor cust_id + atualizar comentários

**Arquivo principal**: `app/Models/Company.php` linha 58.

**Mudança 1 — linha 58 (o fix):**

```diff
public function getCustIdAttribute(): ?string
{
-    $custId = $this->ml_store_id ?: $this->adman_account_id;
+    $custId = $this->adman_account_id ?: $this->ml_store_id;
     return $custId !== '' ? $custId : null;
}
```

**Mudança 2 — comentário do accessor (linhas 42-55):** reescrever para refletir a nova lógica e contexto. Algo nesse sentido (executor pode ajustar texto, mantendo pt-BR):

```php
/**
 * ID canônico de cliente para chamadas Adman e chave de cache de faturamento.
 *
 * Prioriza `adman_account_id` sobre `ml_store_id` porque a Adman API espera
 * o ID Adman da conta. Para 99% das empresas (167/170 em 2026-06-09) os dois
 * IDs são iguais — a Adman usa o seller_id do ML como ID interno para contas
 * meli. Mas onde divergem (ex: ADHARAPRINTSHOP id=189), o ml_store_id retorna
 * HTTP 500 enquanto o adman_account_id retorna os dados corretamente.
 *
 * Histórico: a ordem original era `ml_store_id ?: adman_account_id`, criada
 * para cobrir 3 empresas que só tinham ml_store_id setado. Invertida em
 * 2026-06-09 (quick task 260609-mom) após bug em ADHARA / AVF_2K mostrar
 * que a prioridade correta é a Adman. O fallback continua atendendo as 3
 * empresas ml-only.
 *
 * Retorna null quando a empresa não tem integração Adman/ML configurada.
 */
```

**Mudança 3 — atualizar comentários nos demais arquivos que mencionam a expressão antiga.** São referências em comentários (NÃO em código), encontradas via grep:

```
app/Jobs/RefreshGrossBillingCacheJob.php:93    — comentário menciona "ml_store_id ?: adman_account_id"
app/Http/Controllers/AdminController.php:187    — idem
app/Http/Controllers/AdminController.php:190    — idem
app/Http/Controllers/AdminController.php:482    — idem
app/Http/Controllers/AdminController.php:648    — idem
app/Http/Controllers/CompanyController.php:155  — idem
app/Http/Controllers/DashboardController.php:637 — idem
app/Services/SugadorAnalysisService.php:97      — idem (e linha 97 também menciona "Antes usava ml_store_id ?: adman_account_id" — histórico, manter como está; só atualizar se a frase ficar contraditória)
```

Para cada um: ler o comentário, substituir a expressão `ml_store_id ?: adman_account_id` por `adman_account_id ?: ml_store_id` na frase descritiva. NÃO mudar a lógica do código adjacente. Se o comentário já estiver em forma histórica ("Antes usava X, agora Y"), só atualizar se a nova ordem invalidar o texto.

**Verifies (executor roda antes do commit):**

```bash
# 1) Confirma a mudança no accessor
grep -n "adman_account_id ?: " app/Models/Company.php
# Esperado: 1 linha contendo "adman_account_id ?: $this->ml_store_id"

# 2) Garante que NÃO sobrou a ordem antiga no código (em comentário tudo bem desde que histórico)
grep -rn "ml_store_id ?: \$this->adman_account_id\|ml_store_id ?: \$company->adman_account_id" app/
# Esperado: 0 linhas

# 3) Confirma que o accessor continua retornando null para empresas sem nenhum dos dois
grep -n "return \$custId !== ''" app/Models/Company.php
# Esperado: 1 linha (semântica de null preservada)
```

**Done**: commit atomico em pt-BR.
Mensagem sugerida: `fix(adman): inverte prioridade cust_id para adman_account_id ?: ml_store_id`

## SUMMARY.md esperado

`.planning/quick/260609-mom-cust-id-accessor-priority/260609-mom-SUMMARY.md` em pt-BR com:
- frontmatter `status: complete`, `commits`, `files_modified`
- resumo do fix (causa raiz + decisão + impacto)
- lista dos arquivos cujos comentários foram atualizados
- saída dos 3 verifies
- nota: orquestrador fará deploy + cache flush das empresas #189 e #243 pós-merge
