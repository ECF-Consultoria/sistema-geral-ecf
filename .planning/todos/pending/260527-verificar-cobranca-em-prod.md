---
id: 260527-verificar-cobranca-em-prod
created: 2026-05-27
priority: high
effort_estimate: 30min
category: validation
resolves_phase: 14
references:
  - .planning/phases/14-consolida-o-do-modelo-de-servi-os-frente-b/deferred-items.md
  - .planning/phases/14-consolida-o-do-modelo-de-servi-os-frente-b/14-VERIFICATION.md (item 3)
status: pending
---

# Verificar cobrança em produção com `phase14:verificar-cobranca`

## Contexto

Plan 14-06 dropou as 6 colunas legacy em produção, mas o comando-gate `phase14:verificar-cobranca --abort-on-divergence` nunca rodou contra **dados reais** (ambiente do executor estava com banco zerado — 0 empresas). O comando precisa confirmar que o refator do `AdminController` (via `CobrancaCalculator::novo`) produz o **mesmo resultado** que o cálculo legacy para todas as empresas reais.

## Pré-requisitos

Escolher UMA das opções abaixo:

**Opção A — dump local (recomendado):**

1. Solicitar dump SQL recente do banco de produção (Hostinger VPS `177.7.53.164`).
2. Restaurar em XAMPP local (`mysql -u root ecf_admin_dev < dump.sql`).
3. Confirmar via `php artisan tinker` que `Company::count()` retorna o número esperado de empresas.

**Opção B — janela de manutenção:**

1. Avisar Comercial + Admin para não usar o sistema durante 10min.
2. SSH no VPS Hostinger.
3. Rodar `php artisan phase14:verificar-cobranca --abort-on-divergence` direto.

## Critério de sucesso

```bash
php artisan phase14:verificar-cobranca --abort-on-divergence
# Exit code: 0
# Saída: "✓ N empresas verificadas, 0 divergências"
```

Onde `N` = número total de empresas com `additional_service_price` preenchido OU `service_type` populado pré-drop.

## Se houver divergência

- Abrir bug específico (Rule 1 — quick task `260527-fix-divergencia-cobranca`)
- NÃO remover o comando antes de resolver
- Diagnosticar via `phase14:verificar-cobranca --verbose --company-id=N` (se a flag existir) para isolar a empresa problemática

## Após sucesso (0 divergências)

1. Atualizar `.planning/phases/14-consolida-o-do-modelo-de-servi-os-frente-b/14-VERIFICATION.md` — marcar item 3 como `passed` no frontmatter
2. Atualizar `.planning/phases/14-consolida-o-do-modelo-de-servi-os-frente-b/deferred-items.md` — mover entrada "Comando phase14:verificar-cobranca não pôde ser rodado pelo executor" para "Resolvidos"
3. (Opcional) Remover o comando `app/Console/Commands/Phase14VerificarCobranca.php` + suíte `Phase14VerificarCobrancaTest.php` em commit separado — o comando é histórico, documentado pra ser deletado em RESEARCH §1
4. Mover este TODO para `.planning/todos/completed/`

## Notas

- A suíte `Phase14AdminControllerCobrancaTest` (Plan 14-03) já garante que o **refator** está correto em ambiente controlado. Este comando garante que os **dados reais** não divergem entre o caminho legacy reconstruído (helper `legacy()`) e o cálculo atual via contratos (helper `novo()`).
- Após o drop, o sentinel `Schema::hasColumn('companies', 'additional_'.'service_price')` faz o comando virar smoke check trivial (sempre passa). Logo, o gate efetivo só é válido em ambientes que **ainda têm as colunas legacy** OU restauram dump pré-drop.
