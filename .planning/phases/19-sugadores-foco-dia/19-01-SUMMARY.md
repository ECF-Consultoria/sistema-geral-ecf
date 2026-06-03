---
phase: 19-sugadores-foco-dia
plan: 01
subsystem: sugadores
tags: [filtros-default, cache-lock, copiar-mlbs, limpar-orfaos, banner-d1, testes-feature]
dependency_graph:
  requires: [phase-15-companies-summary, phase-16-analise-diaria, phase-18-cust-id-status]
  provides: [SUG-DEFAULT-01, SUG-COPY-LINHA-01, SUG-COPY-EMPRESA-01, SUG-BANNER-D1-01, SUG-MCP-LOCK-01, SUG-ORFAOS-01]
  affects: [SugadorController, AdmanMcpService, Index.jsx]
tech_stack:
  added: [Cache::lock (DatabaseLock)]
  patterns: [cache-lock-por-custid, filtro-default-auto, endpoint-agregado, 1-clique-copy]
key_files:
  created:
    - app/Console/Commands/LimparOrfaosSugadores.php
    - tests/Feature/Phase19/SugadoresVistaDefaultTest.php
    - tests/Feature/Phase19/LimparOrfaosSugadoresTest.php
    - tests/Feature/Phase19/CopiarMlbsTest.php
  modified:
    - app/Http/Controllers/SugadorController.php
    - app/Models/SugadorAcao.php
    - app/Services/AdmanMcpService.php
    - resources/js/Pages/Sugadores/Index.jsx
    - routes/web.php
    - tests/Feature/Sugadores/SugadoresIndexTest.php
decisions:
  - "Opção A para mlbs-by-company: endpoint backend consolidado (vs loop frontend N calls) — reusa Cache::lock por custId, resposta HTTP única, loading state simples"
  - "sincronizou_hoje como alias semântico de analisado_hoje em companies_summary — mantém compat dos consumers existentes"
  - "copyToClipboard duplicado em Index.jsx (não extraído para lib/utils.js) — follow-up quando houver 3+ consumers"
  - "Limite de 20 adgroups por request em mlbsByCompany — evita HTTP timeout; truncated=true sinaliza ao operador"
  - "analise_diaria usa MAX(created_at) dos sugadores como proxy para ultima_execucao_global — não requer model dedicado"
metrics:
  duration: "~45 min"
  completed: "2026-06-03"
  tasks_completed: 10
  files_changed: 10
---

# Phase 19 Plan 01: Sugadores Foco no Dia Atual — Summary

**One-liner:** Vista default HOJE + botões copiar 1 clique + Cache::lock MCP + comando limpar-orfaos 1407 acumulados.

## O que foi construído

### W1 — Backend (4 tasks)

**W1-T1: Filtros default HOJE + prop analise_diaria** (`daa21cd`)
- `SugadorController::index()` detecta "modo default" (sem filtros explícitos de data/status)
- Aplica automaticamente `reference_date=hoje + status=pendente` quando em modo default
- Nova prop `default_view` ('hoje'|'custom') para o frontend renderizar header correto
- Nova prop `analise_diaria` `{ horario_cron: '12:00 BRT', ultima_execucao_global: ISO-8601 }` via `MAX(created_at)`
- Campo `sincronizou_hoje` adicionado em `companies_summary` (alias semântico de `analisado_hoje`)
- Limite N+1 ajustado no teste existente (15→16) para absorver a query `MAX(created_at)`

**W1-T2: Comando sugadores:limpar-orfaos** (`7d886ce`)
- `app/Console/Commands/LimparOrfaosSugadores.php`: dry-run por default, `--apply` explícito
- Critério: `status='pendente' AND reference_date < hoje` (STATUS_TRAVADOS ficam de fora)
- `--apply`: UPDATE em massa + INSERT em `sugador_acoes` em chunks de 500 dentro de `DB::transaction`
- `SugadorAcao::ACAO_LIMPEZA_ORFAOS = 'limpeza_orfaos'` adicionado ao model

**W1-T3: Cache::lock por custId em AdmanMcpService** (`fae4c13`)
- `fetchAllProductAds()` envolve `Cache::remember` com `Cache::lock("adman_mcp:custid:{custId}", 30)`
- Serializa chamadas concorrentes ao mesmo custId — mitiga 429 MCP
- TTL 30s cobre 16 páginas × 1.5s = 24s + folga TLS
- `LockTimeoutException` capturada e relançada como `RuntimeException` pt-BR
- Lock por custId (não global) — permite paralelismo entre contas distintas

**W1-T4: Endpoint mlbsByCompany** (`da25536`)
- `GET /sugadores/companies/{company}/mlbs-todos` → JSON `{ mlbs, total_mlbs, sugadores_processados, sugadores_solicitados, truncated }`
- Critério: adgroups `pendente + reference_date=hoje` da empresa, limite 20, `truncated=true` se mais
- Autorização: replica `sgiCampaigns()` (admin/gestor/lider global; demais só carteira)
- Reusa `fetchMlbsByCampaign()` que já usa `Cache::lock` do W1-T3

### W2 — Frontend (4 tasks, 1 commit agrupado)

**W2-T1: Banner D-1 expandido + badges de estado** (`6351bb4`)
- Banner: `"Análise diária roda às 12:00 BRT · Última execução: há X horas"` (substitui texto estático)
- Novo componente local `AnaliseBadge`: verde "Análise OK hoje" / cinza "Sem análise hoje"
- `CustIdInvalidoBadge` mantido — quando inválido, `AnaliseBadge` não duplica o badge

**W2-T2: Vista default HOJE + toggle** (`26b2202`)
- Header `"N sugadores HOJE"` em amarelo quando `default_view='hoje'` (modo lista)
- Botão "Ver dias anteriores" → navega para `?include_old=1`
- Botão "Voltar para HOJE" → remove `include_old`
- Estado `include_old` preservado no filtro local para não regredir ao modo default ao filtrar

**W2-T3 + W2-T4: Botões Copiar MLBs** (`dab9756`)
- `copyToClipboard` duplicado de Show.jsx (comentário pt-BR: futuro extração para lib/utils.js)
- Botão inline na linha do sugador (tipo=adgroup): fetch `/sugadores/{id}/mlbs` → clipboard → feedback 2s
- Botão no CompanyCard (count_hoje>0): fetch `/sugadores/companies/{id}/mlbs-todos` → clipboard → feedback 4s
- Loading state com `Loader2`, feedback com `Check` / mensagem de erro
- Texto auxiliar quando `truncated=true`: "(20 de N — copie em partes se necessário)"

### W3 — Testes Feature (3 arquivos, 10 testes)

| Arquivo | Testes | Resultado |
|---------|--------|-----------|
| `SugadoresVistaDefaultTest.php` | 4 | Verde |
| `LimparOrfaosSugadoresTest.php` | 4 | Verde |
| `CopiarMlbsTest.php` | 2 | Verde |
| **Total Phase19** | **10** | **Verde** |

Suíte completa Sugadores após Phase 19: **24 testes verdes** (sem regressão).

## Commits

| Hash | Tipo | Descrição |
|------|------|-----------|
| `daa21cd` | feat | filtros default hoje + analise_diaria + sincronizou_hoje no index() |
| `7d886ce` | feat | comando sugadores:limpar-orfaos + constante ACAO_LIMPEZA_ORFAOS |
| `fae4c13` | feat | Cache::lock por custId em AdmanMcpService::fetchAllProductAds |
| `da25536` | feat | endpoint mlbsByCompany + rota sugadores.mlbs-by-company |
| `6351bb4` | feat | banner D-1 expandido + badges de estado no CompanyCard |
| `26b2202` | feat | vista default HOJE com header destacado + toggle Ver dias anteriores |
| `dab9756` | feat | botoes Copiar MLBs inline (linha) e por empresa (card) |
| `d0e8149` | test | SugadoresVistaDefaultTest - 4 testes de filtros default e analise_diaria |
| `1157749` | test | LimparOrfaosSugadoresTest - 4 testes do comando limpar-orfaos |
| `99832d2` | test | CopiarMlbsTest - 2 testes do endpoint mlbs-by-company e Cache::lock |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Ajuste de limite N+1 no SugadoresIndexTest**
- **Found during:** W1-T1
- **Issue:** Query `MAX(created_at)` adicionada para `analise_diaria` incrementou count de queries de 15 para 16, quebrando o teste existente `test_agregacao_nao_dispara_N_mais_um`
- **Fix:** Atualizado limite de 15→16 no teste com comentário explicativo. Não é N+1 — é query legítima e necessária.
- **Files modified:** `tests/Feature/Sugadores/SugadoresIndexTest.php`
- **Commit:** `daa21cd`

**2. [Rule 1 - Bug] Ajuste de count no test_include_old_inclui_anteriores**
- **Found during:** W3-T1 (primeira execução dos testes)
- **Issue:** O filtro de status padrão exclui apenas `STATUS_RESOLVIDO = 'resolvido'`, não `auto_resolvido`. Com `include_old=1`, o sugador `auto_resolvido` aparece na lista (6 registros, não 5 como o teste esperava)
- **Fix:** Ajustado expect de 5→6 com comentário explicando a distinção de status
- **Files modified:** `tests/Feature/Phase19/SugadoresVistaDefaultTest.php`
- **Commit:** `d0e8149`

## Known Stubs

Nenhum stub identificado. Todos os dados são lidos de fontes reais (banco de dados) e as props retornam valores calculados.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: authorization | `SugadorController::mlbsByCompany` | Novo endpoint de dados de MLBs — mitigado via authorization scope da carteira (T-19-01, T-19-04 no threat model) |

## Pendente W4: Gate Humano

As seguintes ações requerem execução manual pelo operador:

1. **Deploy** — rodar `deploy.sh` ou `deploy_parcial.sh`
2. **Dry-run em prod** — `php artisan sugadores:limpar-orfaos` (esperado: ~1407 candidatos)
3. **Confirmação humana** — colar output do dry-run para revisão antes do `--apply`
4. **Apply** (após OK explícito) — `php artisan sugadores:limpar-orfaos --apply`
5. **Validação UI em prod** — banner, vista HOJE, botões copiar, badges de estado
6. **Smoke 429** — abrir adgroup de conta grande, verificar que não aparece 429

Ver `PLAN.md W4 <how-to-verify>` para passos detalhados.

## Follow-ups

- Extrair `copyToClipboard` para `resources/js/lib/utils.js` quando houver 3+ consumers (atualmente Show.jsx + Index.jsx)
- Se `analise_diaria.ultima_execucao_global` precisar de precisão maior, criar model `SugadorAnaliseRun` em fase futura
- Se Lock TTL de 30s se mostrar insuficiente em prod (contas >16 páginas), calibrar para 45s

## Self-Check: PASSED

Arquivos criados verificados:
- [x] `app/Console/Commands/LimparOrfaosSugadores.php` — existe
- [x] `tests/Feature/Phase19/SugadoresVistaDefaultTest.php` — existe
- [x] `tests/Feature/Phase19/LimparOrfaosSugadoresTest.php` — existe
- [x] `tests/Feature/Phase19/CopiarMlbsTest.php` — existe

Commits verificados (git log):
- [x] `daa21cd` W1-T1
- [x] `7d886ce` W1-T2
- [x] `fae4c13` W1-T3
- [x] `da25536` W1-T4
- [x] `6351bb4` W2-T1
- [x] `26b2202` W2-T2
- [x] `dab9756` W2-T3+T4
- [x] `d0e8149` W3-T1
- [x] `1157749` W3-T2
- [x] `99832d2` W3-T3

Testes Phase19: 10/10 verdes
Testes Sugador (completo): 24/24 verdes
