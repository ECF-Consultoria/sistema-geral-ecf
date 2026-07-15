---
phase: 80-b-nus-e-relat-rios-desempenhoscoreservice-l-atribui-es-por-s
plan: 02
subsystem: bonus
tags: [nps, desempenho, bonus, cache, regressao, dual-path, deploy-blocker]

# Dependency graph
requires:
  - phase: 80-b-nus-e-relat-rios-desempenhoscoreservice-l-atribui-es-por-s
    plan: 01
    provides: "computeNpsMedio dual-path (união disjunta atribuições × legado)"
  - phase: 74-desempenho-engine-v2
    provides: "Fixture Carlos (âncora 4.08/basico) — prova de regressão do ramo legado"
provides:
  - "Chave de cache desempenho.compute.v3 — desbloqueia o deploy do pacote 80"
  - "Suite tests/Feature/V16/BonusDualPathRegressaoTest — regressão histórica, mês misto, 0.0, guard de carteira e prova do bump"
  - "Varredura completa dos 8 consumidores de computeCached (inclui PortfolioController)"
  - "Prova medida de que o dual-path não alterou bônus de nenhum mês sem atribuição"
affects: [80-03 (leitores de apresentação), deploy do pacote NPS v16.0]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Bump de chave versionada como parte OBRIGATÓRIA de toda correção de régua cacheada"
    - "Teste de cache semeando lixo reconhecível na chave ANTIGA (prova por exclusão)"
    - "Verificação por MUTAÇÃO: desligar a proteção e exigir que o teste falhe com o valor previsto"
    - "Isolamento de variável: alternar só a linha suspeita no MESMO ambiente em vez de worktree degradado"

key-files:
  created:
    - tests/Feature/V16/BonusDualPathRegressaoTest.php
  modified:
    - app/Services/DesempenhoScoreService.php

key-decisions:
  - "Bump aplicado com cirurgia mínima: só a versão da string; TTL adaptativo e Cache::remember intactos"
  - "Comentário do bump v1→v2 preservado como histórico, conforme o plano"
  - "Nenhum cache:clear adicionado a script algum — chaves v2 viram órfãs e expiram por TTL; WarmDesempenhoCache repopula a v3 sozinho"
  - "Mês misto testado sob o modelo PRINCIPAL de propósito — é o que torna a duplicação detectável"
  - "Teste do cache usa user SEM carteira: compute() corta cedo e não toca o MetricsProviderFactory (zero HTTP no teste)"
  - "Fatal de 300s da suite completa diagnosticado como pré-existente e ambiental (set_time_limit(300) das Grants + usleep contando wall-clock no Windows)"

requirements-completed: [DEC-80-B, DEC-80-C, DEC-80-E]

# Metrics
duration: ~55min
completed: 2026-07-15
---

# Phase 80 Plan 02: Regressão do dual-path + cache v3 — Summary

**O bônus histórico foi provado intacto por medição (não por argumento), o mês de transição soma os dois caminhos com cada resposta contando exatamente 1×, e a chave de cache subiu para v3 — o item que desbloqueia o deploy do pacote 80.**

## Performance

- **Duração:** ~55 min
- **Tarefas:** 3/3
- **Arquivos:** 1 criado (teste), 1 modificado (1 linha de código de produção)
- **Commits:** 3 (test, fix, docs)

## Accomplishments

### Tarefa 1 — Regressão do dual-path (`tests/Feature/V16/BonusDualPathRegressaoTest.php`)

4 testes, verdes contra o código do 80-01 exatamente como o plano previa:

| Teste | Prova |
|---|---|
| `test_mes_sem_atribuicoes_mantem_nota_legada` | 2 respostas por factory (sem `NpsSnapshotService`) → média 100% legado = **3.0**. `assertSame(0, NpsScoreAssignment::count())` garante que é mesmo o ramo legado sendo medido |
| `test_mes_misto_soma_os_dois_caminhos_sem_duplicar` | resposta antiga (factory) + nova (submit real), MESMO mês/user → **3.5**; duplicada daria 3.0 |
| `test_nps_medio_e_zero_sem_respostas_no_mes_com_carteira` | `assertSame(0.0, ...)` estrito (DESEMP-03 penaliza; `null` deixaria de penalizar) |
| `test_nps_medio_usa_atribuicao_mesmo_sem_carteira_ativa` | trava o guard que desceu para `notasLegado` no 80-01 (empresa inativa → carteira vazia, atribuição preservada) |

**Verificação por mutação (não pedida pelo plano, feita por rigor):** um teste de regressão que só passa não prova nada — provei que ele sabe falhar. Desabilitando o skip de disjunção em `notasLegado`, o mês misto cai para **3.0** (exatamente a aritmética prevista: `(2+2+5)/3`), e volta a 3.5 com o skip restaurado. O teste discrimina de fato.

### Tarefa 2 — Bump v2 → v3 (`DesempenhoScoreService.php:150`)

Cirurgia mínima: **só a versão da string**. TTL adaptativo (7d mês fechado / 10min mês em curso) e o `Cache::remember` intactos; comentário do bump v1→v2 preservado.

`test_cache_bumpado_para_v3` semeia lixo reconhecível (`nota_final => 99.9`) na chave **v2** e prova que `computeCached` ignora e recalcula, que a v3 passa a existir, e que a v2 fica órfã. **Também mutation-verified:** revertida a chave para v2, o teste falha com `Failed asserting that 99.9 is not identical to 99.9` — ele detecta um bump ausente.

**Varredura dos consumidores (acceptance da Tarefa 2 — todos conferidos):**

```
ÚNICA linha que monta a chave: DesempenhoScoreService.php:150   ← nenhum consumidor a monta por fora
PerformanceController   :108 :114 :272 :907   ─┐
DashboardController     :797                   ├─ todos via computeCached → cobertos pelo bump
PortfolioController     :1251 :1277            │   (o que o CONTEXT não listava — RESEARCH C2)
WarmDesempenhoCache     :71                   ─┘   repopula a v3 sozinho (cron 8min)

compute() DIRETO (intocados, por design — DEC-80-E):
SnapshotDesempenhoScores :89     ConsolidarMesDesempenho :97
```

- `grep "desempenho.compute.v3" app/Services/DesempenhoScoreService.php` → **1 linha de código** ✔
- `grep "desempenho.compute.v2" app/` → **nenhuma ocorrência** ✔
- Nenhum `cache:clear` adicionado a script algum ✔
- `desempenho:consolidar-mes --mes` passado **nunca executado** ✔

### Tarefa 3 — Regressão ampla

| Gate | Resultado |
|---|---|
| `--filter=BonusDualPathRegressaoTest` | **5/5 verdes** |
| `--filter=Desempenho` | 56 testes, 1 falha — **idêntico ao baseline que capturei ANTES de tocar em qualquer coisa** |
| **`test_fixture_carlos_retorna_nota_4_08_basico`** | **VERDE — 4.08 / `basico`, sem nenhuma edição no arquivo de teste** |
| `tests/Feature/Phase74` (casa da âncora) | **OK (32 testes, 119 assertions)** |
| `--filter=Performance` | **37/37 verdes** |
| `--filter=Nps` | **174/174 verdes** |
| `tests/Feature/V16` (inclui Fase 79) | **OK (72 testes, 312 assertions)** |
| `tests/Feature/Portfolio` | **OK (7 testes, 92 assertions)** |
| `git diff --stat b90bf1f..HEAD -- tests/Feature/Phase74/ tests/Feature/V16/SubmitSnapshotTest.php` | **vazio** ✔ |

**Blast radius medido:** `git diff --stat b90bf1f..HEAD` = **2 arquivos** — `DesempenhoScoreService.php` (1 linha de código + 8 de comentário) e o teste novo. **Nenhum teste fora de `tests/Feature/V16/` foi tocado.**

**Sobre o `--filter=Nps` ter ido de 172 (80-01) para 174:** os 2 a mais são exatamente os meus dois testes `test_nps_medio_*`, que o `--filter` casa por nome de método. Zero testes pré-existentes da Fase 79 mudaram de comportamento.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Bloqueio] O gate "suite completa verde" é impossível nesta máquina — causa pré-existente, diagnosticada e provada**

- **Encontrado em:** Tarefa 3, gate 4 (`vendor/bin/phpunit` sem filtro)
- **Sintoma:** `Fatal error: Maximum execution time of 300 seconds exceeded in MercadoLivreAdsService.php:215`
- **Diagnóstico (não é o que parece):** o `php.ini` do CLI já tem `max_execution_time=0`; o limite é **re-armado em runtime** por `set_time_limit(300)` dentro dos comandos de Grants (`SyncGrantsFromSftp.php:22`, `SyncGrantsFromEcfDrive.php:23`, `GrantController.php:287/327`). A partir daí o processo inteiro do phpunit passa a ter 300s de orçamento. Como **no Windows o `usleep` conta wall-clock** contra esse limite (no Linux não conta), a suite morre dentro do backoff exponencial dos testes de Sugadores — que **passam sozinhos** (`MercadoLivreAdsServiceBackoffTest` 13/13, `MercadoLivreAdsServiceTest` 4/4). Não é teste quebrado: é orçamento de tempo estourado.
- **Prova de que é pré-existente:** worktree no commit `b90bf1f` (**antes** do 80-02) → **mesmo fatal de 300s**.
- **Mitigação:** suite completa coberta **em chunks** sob o orçamento (Unit + 54 diretórios de Feature + os 37 arquivos soltos da raiz). Não corrigido — SCOPE BOUNDARY (registrado em `deferred-items.md`).

**2. [Rule 3 — Bloqueio] Baseline por worktree é enganoso; troquei por isolamento de variável**

- **Encontrado em:** Tarefa 3, ao tentar provar que as falhas dos outros módulos eram pré-existentes
- **Issue:** o worktree em `b90bf1f` reportou **MAIS** falhas que o HEAD (Phase18: 14 F vs 2 F; assertions 65 vs 273). O worktree não tem `public/build/manifest.json` nem o scaffolding de `storage/` → a renderização Inertia falha cedo e o resultado é ruído. Um baseline degradado teria me feito concluir "o 80-02 melhorou 40 testes", que é falso.
- **Fix:** experimento de **variável única no ambiente REAL** — alternar só a linha da chave (`v3` → `v2` → `v3`) e rodar os mesmos chunks. Resultado **idêntico em todos**, assertion count incluso (ver tabela abaixo). É a prova apples-to-apples que o worktree não conseguiu dar.

## Deferred Issues

**Falhas pré-existentes fora do escopo** (registradas em `deferred-items.md`) — **todas medidas como idênticas com a chave em v2 e em v3, no mesmo ambiente**:

| Chunk | chave v2 (pré-80-02) | chave v3 (HEAD) |
|---|---|---|
| Phase18 | 25T, 273A, 2F | 25T, 273A, 2F |
| Phase38 | 26T, 234A, 5F | 26T, 234A, 5F |
| Phase38Publicador | 5T, 68A, 2F | 5T, 68A, 2F |
| Phase42 | 42T, 236A, 5F, 1S | 42T, 236A, 5F, 1S |
| Phase75 | 43T, 150A, 3F | 43T, 150A, 3F |
| Phase77 | 33T, 87A, 1F | 33T, 87A, 1F |
| Polos | 4T, 32A, 2E/2F/2R | 4T, 32A, 2E/2F/2R |

Além destes: `PublicacaoDesempenhoRouteTest` (403≠200, já registrado no 80-01), `tests/Unit` com 9 erros de `CalcularFaixaTest` (assinatura de `AdminController::__construct()` desatualizada no teste), `CompanyServiceTypeTest::test_service_type_aceita_polo` (a armadilha conhecida do enum `polos` no SQLite), 2 de `MercadoLivreSugadoresProviderTest` e os `/dev/*` 404 de `DevControllerTest` (rotas removidas, testes não atualizados).

**Por que nenhuma delas pode ser minha (argumento estrutural, independente da medição):**
1. `CACHE_STORE=array` no `phpunit.xml` → o cache **começa vazio em todo teste**; renomear uma chave é no-op para quem não a hardcoda.
2. O **único** arquivo em todo o repo que hardcoda `desempenho.compute` é o teste que eu escrevi.
3. Nenhum dos chunks que falham referencia `computeCached` nem `DesempenhoScore` (`tests/Unit/` tem **zero** referências).

## Known Stubs

Nenhum.

## Threat Flags

Nenhuma superfície de segurança nova. Mitigações do `<threat_model>` aplicadas e cobertas por teste:

| Threat ID | Mitigação aplicada | Prova |
|---|---|---|
| T-80-06 | Bump v2→v3 + varredura dos 8 consumidores | `test_cache_bumpado_para_v3` (mutation-verified: com v2 ele falha) |
| T-80-07 | `consolidar-mes --mes` passado nunca executado; `Snapshot`/`Consolidar` seguem em `compute()` direto e não foram alterados | `git diff --stat` = 2 arquivos, nenhum de consolidação |
| T-80-08 | Régua histórica preservada | `test_mes_sem_atribuicoes_mantem_nota_legada` + âncora Carlos 4.08/`basico` sem edição do arquivo |
| T-80-09 | Escrita da Fase 79 intocada | `--filter=Nps` 174/174; `tests/Feature/V16` 72/72; diff vazio nos testes da Fase 79 |
| T-80-SC | Nenhuma dependência instalada | `composer.json`/`package.json` intocados |

## Avisos operacionais para o deploy

1. **O `delta_vs_ontem` do ranking vai registrar um DEGRAU no dia do deploy — isso é a correção, não bug.** O snapshot diário passa a comparar a nota nova (com a atribuição do Shopee somando) contra a nota de ontem, calculada pela régua antiga. Quem responde por Shopee sobe/desce de uma vez. Avisar o time antes de alguém abrir chamado.
2. **O bump de cache é o que faz a correção aparecer.** Sem ele o código novo sobe e o Redis serve a nota antiga por até 7 dias no mês fechado. Nenhum `cache:clear` é necessário: as chaves v2 viram órfãs e expiram por TTL, e o `WarmDesempenhoCache` (cron 8min) repopula a v3 sozinho. **Não** rodar `desempenho:consolidar-mes --mes` passado (DEC-80-E — reescreveria bônus já pago).
3. **Dimensionar o ramo legado permanente (RESEARCH OQ3)** — rodar no VPS pós-deploy:
   ```bash
   grep '\[NPS Snapshot\] responsável faltante' storage/logs/laravel*.log | wc -l
   grep '\[NPS Snapshot\] responsável faltante' storage/logs/laravel*.log \
     | grep -oE '"company_id":[0-9]+' | sort -u | wc -l
   ```
   Cada linha é uma resposta que **nunca** gerou atribuição (empresa com `company_users.servico_id = NULL` no backfill) e que ficará **permanentemente** no ramo legado. O número de `company_id` distintos é o tamanho real da fila de reconciliação de responsáveis — o ramo legado não é dívida a remover, é fallback definitivo enquanto essas pendências existirem.
4. **Backend-only:** nenhum `npm run build`, nenhum deploy executado. Dev em paralelo (anunciar-ml) — reconciliar antes do deploy (memória `feedback_perguntar_antes_deploy_v9`).

## Notas para o próximo plano

- **80-03 (leitores de apresentação):** os widgets que usam `->principal()` diretamente (`PerformanceController::dashboardCarteira` :298-446 — coluna NPS por empresa, últimas respostas, heatmap) continuam mostrando só o modelo principal. Ranking e headline `nps.media` já se consertaram sozinhos via `computeCached`.
- Ao fim da fase, atualizar a memória `project_nps_modelo_principal` com a nuance: "só o principal conta" segue valendo no **ramo legado** (é o isolamento por serviço), mas foi superada no **ramo das atribuições**.

## Self-Check: PASSED

- `tests/Feature/V16/BonusDualPathRegressaoTest.php` — FOUND (5 testes, 490 linhas)
- `app/Services/DesempenhoScoreService.php:150` contém `desempenho.compute.v3` — FOUND
- Commit `f96218d` (test — regressão) — FOUND
- Commit `4ba28b9` (fix — bump v3) — FOUND
- Árvore de trabalho sem modificações não-commitadas — CONFIRMADO
- `tests/Feature/Phase74/` sem edição (âncora Carlos) — CONFIRMADO
