---
phase: 25-analise-por-empresa-sellers-pagina-detail-360-graus-com-sell
plan: 01
subsystem: ecf-drive-analise
tags: [ecf-drive, seller, ficha-360, auth-por-carteira, recharts, inertia]
dependency_graph:
  requires: [22-01, 23-01, 24-01]
  provides: [empresas.analise-ecf, EmpresaAnaliseEcfController, ecfDriveLabels.js]
  affects: [AlertaCard.jsx, BreakdownTabs.jsx]
tech_stack:
  added: []
  patterns:
    - EcfDriveService (4 chamadas wrapper em try/catch global — seller/metricasMensal/medalhas/signals)
    - Auth fina inline por carteira (abort_unless + companies()->where('companies.id', X)->exists())
    - Refator label-extract para lib/ecfDriveLabels.js (CLUSTER/FRETE/PROGRAMA_LABELS + traduzirItem)
    - recharts LineChart duplo eixo Y (GMV esq + Inv ADS dir)
    - Timeline horizontal scroll-x (HistoricoMedalhas)
    - Read-only display de signals (AlertasDoSeller sem botão ack)
key_files:
  created:
    - app/Http/Controllers/EmpresaAnaliseEcfController.php
    - resources/js/lib/ecfDriveLabels.js
    - resources/js/Pages/EmpresaAnaliseEcf/Show.jsx
    - resources/js/Pages/EmpresaAnaliseEcf/components/EmpresaHeader.jsx
    - resources/js/Pages/EmpresaAnaliseEcf/components/EvolucaoEmpresaChart.jsx
    - resources/js/Pages/EmpresaAnaliseEcf/components/HistoricoMedalhas.jsx
    - resources/js/Pages/EmpresaAnaliseEcf/components/AlertasDoSeller.jsx
    - tests/Feature/Phase25/EmpresaAnaliseEcfControllerTest.php
  modified:
    - routes/web.php
    - resources/js/Pages/PainelExecutivo/components/BreakdownTabs.jsx
    - resources/js/Pages/AlertasEstrategicos/components/AlertaCard.jsx
decisions:
  - "Refator label-extract W1-T1 executado ANTES do controller para garantir zero regressão Phase 24"
  - "Company::factory() não existe — testes usam Company::create() com CNPJ sequencial"
  - "publicador não é role válido em users.role (CHECK constraint admin/consultor/mentor) — teste de bloqueio adaptado para 302 guest"
  - "HandleInertiaRequests chama /signals para badge sidebar — mocks de teste incluem esse endpoint"
  - "Http::assertNothingSent() inapropriado quando middleware faz chamadas; substituído por Http::assertNotSent(fn => str_contains(url, '/sellers/'))"
metrics:
  duration: "~45min"
  completed: "2026-06-05"
  tasks_completed: 9
  files_changed: 11
---

# Phase 25 Plan 01: Análise por Empresa (Ficha 360° via ECF Drive) — Summary

**Uma linha:** Ficha 360° de empresa em `/empresas/{company}/analise-ecf` com auth fina por carteira, 4 chamadas EcfDriveService em try/catch global, 5 seções UI (Header/KPIs/Evolução/Medalhas/Alertas) e refator de labels para `lib/ecfDriveLabels.js`.

## Waves Executadas

| Wave | Tasks | Status |
|------|-------|--------|
| W1 — Refator + Backend | W1-T1 (labels), W1-T2 (controller + rota) | Concluído |
| W2 — Frontend | W2-T1..W2-T5 (5 componentes + build) | Concluído |
| W3 — Testes | W3-T1 (7 testes verdes) | Concluído |
| W4 — Smoke visual prod | W4-T1 | **PENDENTE — checkpoint humano** |

## Commits por Task

| Task | Commit | Descrição |
|------|--------|-----------|
| W1-T1 | `82c7f30` | refactor(phase-25): extrai CLUSTER/FRETE/PROGRAMA_LABELS para lib/ecfDriveLabels.js |
| W1-T2 | `f73dd52` | feat(phase-25): EmpresaAnaliseEcfController + rota /empresas/{company}/analise-ecf |
| W2-T1 | `4dc8346` | feat(phase-25): EmpresaHeader com medalha colorida e links |
| W2-T2 | `c69b099` | feat(phase-25): EvolucaoEmpresaChart 12m duplo eixo Y |
| W2-T3 | `a79c8d5` | feat(phase-25): HistoricoMedalhas timeline horizontal |
| W2-T4 | `488e83f` | feat(phase-25): AlertasDoSeller lista read-only |
| W2-T5 | `dc8eb7b` | feat(phase-25): Show + AlertaCard linka analise-ecf + build |
| W3-T1 | `4c7ee9e` | test(phase-25): EmpresaAnaliseEcfControllerTest 7 testes verdes |

## Verificações Automatizadas

- **Refator W1-T1**: `grep -c "const CLUSTER_LABELS" BreakdownTabs.jsx` = 0 (constantes removidas)
- **Rota**: `php artisan route:list --name=empresas.analise-ecf` mostra 1 rota com middleware correto
- **Suite Phase 24**: 8/8 testes verdes — zero regressão
- **Suite Phase 25**: 7/7 testes verdes
- **npm run build**: concluído em 13.47s sem erros novos

## Deviações do Plano

### Auto-diagnosticadas e Corrigidas

**1. [Rule 1 - Bug] Company::factory() não existe neste projeto**
- **Encontrado durante:** W3-T1
- **Problema:** Testes usavam `Company::factory()->create()` que lançava `Class "Database\Factories\CompanyFactory" not found`
- **Correção:** Substituído por `Company::create()` com CNPJ sequencial gerado por `$this->cnpjSeq++`
- **Commit:** `4c7ee9e`

**2. [Rule 1 - Bug] Role 'publicador' não existe em `users.role` (CHECK constraint)**
- **Encontrado durante:** W3-T1
- **Problema:** `users.role` é ENUM(`admin`, `consultor`, `mentor`) com CHECK constraint — inserir `role='publicador'` causava SQLSTATE 23000
- **Correção:** Teste `test_publicador_403` substituído por `test_guest_redirecionado_302` (cenário igualmente válido: verifica que middleware `auth` bloqueia com 302)
- **Commit:** `4c7ee9e`

**3. [Rule 1 - Bug] `Http::assertNothingSent` falha quando HandleInertiaRequests chama /signals**
- **Encontrado durante:** W3-T1
- **Problema:** O middleware `HandleInertiaRequests::countAlertasCriticos()` chama `EcfDriveService::listSignals()` em toda página Inertia — isso dispara uma requisição HTTP real mesmo na branch `semCustId`. `Http::assertNothingSent()` falhava por causa dessa chamada do middleware (não do controller)
- **Correção:** (a) Adicionado mock `'*/signals*'` em todos os helpers de fake; (b) `Http::assertNothingSent()` substituído por `Http::assertNotSent(fn => str_contains(url, '/sellers/'))` — verifica precisamente que o controller não chamou endpoints de seller
- **Commit:** `4c7ee9e`

## Decisões de Implementação

| ID | Decisão | Motivação |
|----|---------|-----------|
| D-11 | MEDALHA_BADGE local (não compartilhado) | Apenas 2 usos (EmpresaHeader + HistoricoMedalhas) — promover para lib só quando 3+ usos |
| D-05 | 4 chamadas sequenciais (não paralelas) | Cache do wrapper absorve; paralelizar exige Http::pool com complexidade desproporcional |
| D-06 | `array_slice($signals, 0, 20)` no controller | Wrapper não aceita `limit`; slice no PHP antes de passar pra view |
| D-07 | `traduzirItem` movido para `lib/ecfDriveLabels.js` | 3 phases usariam (24 já usa, 25 usa programa, 27 futura usará todas) |

## Artefatos Criados

### Backend
- `app/Http/Controllers/EmpresaAnaliseEcfController.php` — controller com auth fina + 4 chamadas ECF Drive + try/catch global
- `routes/web.php` — 1 rota nova `GET /empresas/{company}/analise-ecf` (empresas.analise-ecf)

### Frontend (novos)
- `resources/js/lib/ecfDriveLabels.js` — CLUSTER_LABELS/FRETE_LABELS/PROGRAMA_LABELS + traduzirItem
- `resources/js/Pages/EmpresaAnaliseEcf/Show.jsx` — página coordenadora com 4 branches
- `resources/js/Pages/EmpresaAnaliseEcf/components/EmpresaHeader.jsx` — header com badge medalha
- `resources/js/Pages/EmpresaAnaliseEcf/components/EvolucaoEmpresaChart.jsx` — recharts duplo eixo Y
- `resources/js/Pages/EmpresaAnaliseEcf/components/HistoricoMedalhas.jsx` — timeline horizontal
- `resources/js/Pages/EmpresaAnaliseEcf/components/AlertasDoSeller.jsx` — lista read-only signals

### Frontend (modificados)
- `resources/js/Pages/PainelExecutivo/components/BreakdownTabs.jsx` — importa de ecfDriveLabels.js
- `resources/js/Pages/AlertasEstrategicos/components/AlertaCard.jsx` — link empresa → analise-ecf

### Testes
- `tests/Feature/Phase25/EmpresaAnaliseEcfControllerTest.php` — 7 testes verdes

## Known Stubs

Nenhum. Todos os componentes renderizam dados reais das props do controller.
O único campo sem delta MoM nos KPI cards (`deltaPct=null`) é intencional — cálculo MoM via `metricas` é trabalho futuro (Phase 25.1 ou follow-up).

## Threat Flags

Nenhuma nova superfície de segurança além do que está no threat model do PLAN (T-25-01 a T-25-09).

## Próximos Passos

- **W4 smoke visual** (este plan) — 6 cenários em prod com 3 empresas reais
- **Phase 26** — Webhooks HMAC (próximo na fila)
- **Follow-ups possíveis:**
  - Calcular delta MoM nos KPI cards usando `metricas[last] vs metricas[last-1]`
  - Comparação 2+ sellers (wrapper já tem `compararSellers()`)
  - Link "Ver análise ECF" no Dashboard e Companies/Index
  - Expor `rankingDoSeller` quando wrapper Phase 27 disponibilizar

## Self-Check: PASSED

Todos os 8 arquivos criados encontrados no disco.
Todos os 8 commits encontrados no histórico git.
