---
phase: 150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
plan: 07
subsystem: ui
tags: [laravel, inertia, react, phpunit, allow-list, server-side-filter]

# Dependency graph
requires:
  - phase: 150-04
    provides: "Company::comPendenciaAberta() — único caminho de leitura de pendência por query (D-19)"
  - phase: 150-06
    provides: "Gate estático de ponto único de escrita de companies.etapa, provado ativo — leitura (where('etapa', ...)) explicitamente tolerada pelo gate"
provides:
  - "Filtro server-side ?etapa= (allow-list de Company::ETAPAS + sentinela sem_etapa) e ?com_pendencia= em GET /companies"
  - "Dois controles independentes e combináveis na aba Empresas (seletor de etapa + toggle de pendência), sem Array.filter de cliente"
  - "Prop filters ecoando etapa/com_pendencia para o front"
  - "tests/Feature/Phase150/EtapaFiltroListagemTest.php — 9 testes provando os 6 cenários do contrato"
  - "Verificação humana real da tela, aprovada pelo usuário"
  - "Fase 150 fecha as 6 Success Criteria — ETAPA-05 era o último requirement pendente"
affects: [138, 142]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Allow-list server-side com fallback null silencioso, mesmo padrão já usado por cust_id_status"
    - "Dois filtros independentes preservando query params um do outro via router.get, copiando a disciplina de aplicarSort"
    - "Payload por empresa expõe tem_pendencia (não pendencia_aberta) para não colidir com o gate estático D-19 de EtapaPendenciaParaleloTest.php, que proíbe a string crua da coluna fora de Company.php"

key-files:
  created:
    - tests/Feature/Phase150/EtapaFiltroListagemTest.php
  modified:
    - app/Http/Controllers/CompanyController.php
    - resources/js/Pages/Companies/Index.jsx

key-decisions:
  - "Payload por empresa usa a chave tem_pendencia, não pendencia_aberta — nome escolhido em Task 1 para não colidir com o gate estático D-19 de EtapaPendenciaParaleloTest.php, que recusa a string crua da coluna fora de Company.php"
  - "Item 7 (Task 3 <action>): full php artisan test substituído pelo subset seguro (Unit/Phase150 + Feature/Phase150 + as duas suítes baseline de /companies) — a suíte Feature completa não termina neste worktree (trava em timeout de rede de 300s, medido por 150-01 ANTES de qualquer código desta fase existir). Ver seção 'Dívida explícita' abaixo"
  - ".env do worktree corrigido pelo orquestrador (APP_URL/ASSET_URL apontando para /ecf_fluxo_entrada/public, não /ecf_admin/public) — achado de ambiente, não código desta fase, não commitado (arquivo é gitignored)"

patterns-established:
  - "Payload de listagem admin: quando uma chave de contrato colidiria com um gate estático de outro plano da mesma fase, renomear no payload de saída (não no model) e documentar a colisão no commit"

requirements-completed: [ETAPA-05]

duration: ~50min (17:02–17:52 -03:00, incluindo a pausa para verificação humana)
completed: 2026-09-01
---

# Phase 150 Plan 07: Filtros server-side de etapa e pendência em /companies Summary

**Dois filtros independentes e combináveis (`?etapa=` com allow-list + sentinela `sem_etapa` de primeira classe, e `?com_pendencia=` via `comPendenciaAberta()`) na aba Empresas de `/companies`, verificados por um humano na tela real — fecha a Fase 150 (todas as 6 Success Criteria e os 6 requirements ETAPA-01..06).**

## Performance

- **Duration:** ~50 min (início 2026-09-01T20:02:42Z, fim 2026-09-01T20:51:36Z — inclui a pausa para verificação humana entre a Task 2 e a aprovação da Task 3)
- **Started:** 2026-09-01T20:02:42Z
- **Completed:** 2026-09-01T20:51:36Z
- **Tasks:** 3/3 (2 `auto` + 1 `checkpoint:human-verify`, aprovado)
- **Files modified:** 3 (2 modificados, 1 criado)

## Accomplishments

- `CompanyController::index()` ganhou dois filtros server-side independentes: `?etapa=` (allow-list fechada contra `Company::ETAPAS` + sentinela `sem_etapa`, fallback `null` silencioso) e `?com_pendencia=` (via `Company::comPendenciaAberta()`, nunca leitura direta do flag — D-19)
- A aba Empresas ganhou dois controles na tela — seletor de etapa (11 opções, "Sem etapa (legado)" na segunda posição de propósito) e toggle "Com pendência" — 100% server-side, um preservando o outro na URL
- `tests/Feature/Phase150/EtapaFiltroListagemTest.php` — 9 testes cobrindo etapa concreta, `sem_etapa`, valor inválido (fallback), pendência isolada, combinação etapa+pendência, visão padrão sem filtro (Success Criteria nº 1 da fase) e a prop `filters`
- Verificação humana real: o usuário navegou `http://localhost/ecf_fluxo_entrada/public/companies` e confirmou os 6 passos do checkpoint (lista padrão inalterada, "Sem etapa (legado)" em 2º lugar, filtro server-side comprovado pela mudança de contagem nas abas, combinação preservada)
- **A Fase 150 fecha inteira com este plano** — ETAPA-05 era o único requirement dos 6 (`ETAPA-01`..`ETAPA-06`) ainda pendente; as 6 Success Criteria do ROADMAP para a Fase 150 estão todas satisfeitas

## Task Commits

Each task was committed atomically:

1. **Task 1: Filtros server-side em `CompanyController::index()`** - `7e21aa92` (feat)
2. **Task 2: Os dois controles na tela** - `4c89cc6d` (feat)
3. **Task 3: Verificação humana da tela e fechamento da fase** - checkpoint aprovado pelo usuário ("aprovado"); não gera commit de código próprio — a task é a verificação em si, registrada aqui e no commit de metadados abaixo

**Plan metadata:** commit desta SUMMARY + STATE.md + ROADMAP.md + REQUIREMENTS-v23.md (ver commit ao final desta execução)

## Files Created/Modified

- `app/Http/Controllers/CompanyController.php` - parse de `?etapa=`/`?com_pendencia=` com allow-list, aplicação na cadeia `when()` depois do recorte base, payload por empresa ganha `etapa`/`tem_pendencia`, prop `filters` ecoa os dois
- `resources/js/Pages/Companies/Index.jsx` - `ETAPA_LABELS`, seletor de etapa (11 opções), toggle "Com pendência", `aplicarEtapaFilter`/`aplicarComPendenciaFilter` via `router.get` preservando os demais filtros
- `tests/Feature/Phase150/EtapaFiltroListagemTest.php` (criado) - 9 testes provando o contrato dos dois filtros, a combinação, o fallback e a prop `filters`

## Decisions Made

1. **`tem_pendencia` em vez de `pendencia_aberta` no payload.** Task 1 descobriu que o nome originalmente óbvio (`pendencia_aberta`, o mesmo nome da coluna) colidiria com o gate estático de `EtapaPendenciaParaleloTest.php` (plano 150-04, D-19), que recusa a string crua da coluna aparecendo fora de `Company.php`. Resolvido expondo `tem_pendencia` no payload de listagem, mantendo a leitura via `Company::comPendenciaAberta()`/`pendenciaAberta()` — nenhuma leitura direta do flag no controller.
2. **Item 7 da Task 3 — suíte completa substituída pelo subset seguro, com decisão registrada.** O `<action>` da Task 3 pede `php artisan test` comparado contra `150-BASELINE-TESTES.md`. `150-BASELINE-TESTES.md` (criado pelo plano 150-01, **antes** de qualquer código desta fase existir) já documentou que a suíte Feature completa (531 arquivos) não termina neste worktree — trava numa cascata de timeout de rede de 300s por uma chamada Guzzle não mockada, sem `@group network` para isolar rapidamente. Nesta sessão, o orquestrador ainda sondou os quatro candidatos mais prováveis de serem o teste ofensor (`Phase16/AdmanCadenceTest`, `Phase60/BaselineRegressionTest`, `Phase60/UnifiedMetricsServiceTest`, `Phase39/AnalyzeSugadoresCommandTest`) — todos passam limpos, 28/28 em 12s — então o teste que trava não é um desses e bisectar o resto custaria ~300s por tentativa. Decisão: rodar o subset seguro (`tests/Unit/Phase150 tests/Feature/Phase150` + as duas suítes baseline de `/companies`) e registrar a dívida explicitamente aqui, para sobreviver ao `/gsd:verify-work` em vez de ficar implícita.
3. **`.env` corrigido pelo orquestrador, fora do escopo desta fase, não commitado.** Ver "Achados da verificação humana" abaixo.

## Resultado da suíte segura (Task 3)

Comando:
```
C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Phase150 tests/Feature/Phase150 tests/Feature/Phase37CompaniesPerformanceFilterTest.php tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php --colors=never
```

Resultado: **OK (70 tests, 154 assertions)** — código de saída 0. Bate com o número já registrado nas notas de ambiente (última medição: 70/70).

`ls public/hot` — arquivo ausente, nenhum órfão deixado para trás. `public/build/manifest.json` presente e atual (gerado pela Task 2, 17:18 -03:00).

**Full suite (`php artisan test`) NÃO foi rodada nesta task — decisão registrada no item "Decisions Made" nº 2 acima.**

## Achados da verificação humana (Task 3)

Estes dois pontos surgiram durante a verificação humana real na tela. Nenhum dos dois é falha deste plano — registrados aqui porque são achados caros e não dedutíveis do código.

### 1. Defeito de ambiente encontrado e corrigido (fora do escopo desta fase, não commitado)

Toda página servida por este worktree estava com tela branca. Causa raiz: o `.env` do worktree foi copiado de `ecf_admin` e ainda tinha `APP_URL`/`ASSET_URL` = `http://localhost/ecf_admin/public`. Como resultado, o worktree renderizava HTML a partir do SEU PRÓPRIO manifest do Vite (hash `app-WtxLw2bP.js`) mas apontava o navegador para o diretório de build do OUTRO checkout, onde esse hash não existe — 404 no módulo de entrada, React nunca monta. O erro "Vite manifest not found" que o plano 150-01 já havia corrigido mascarava este segundo problema, distinto, por baixo.

O orquestrador corrigiu editando o `.env` local do worktree (arquivo não versionado, gitignored, NÃO commitado, fora do escopo desta fase) para `http://localhost/ecf_fluxo_entrada/public`. Ambos os bundles (`ecf_admin` e `ecf_fluxo_entrada`) agora respondem HTTP 200 corretamente cada um para o seu próprio checkout. **Não reverter esta correção. `.env` continua fora de versionamento — confirmado `git status --short` sem `.env` listado.**

### 2. Observação de UX, explicitamente NÃO um defeito deste plano

O filtro de etapa também estreita a contagem da aba "Pendências" (9 → 8 quando `?etapa=sem_etapa`). Verificado como comportamento pré-existente, não vazamento novo: as três abas são alimentadas por UMA única query, `pendentes` deriva do mesmo prop `companies` (`Index.jsx:301`), e o filtro de etapa deste plano entra na mesma cadeia `when()` que o filtro pré-existente `cust_id_status` já usava — ou seja, `cust_id_status` sempre teve o mesmo efeito colateral. D-23 (os dois filtros NOVOS — etapa e pendência — independentes ENTRE SI) está satisfeito; o que se observa é a interação de qualquer filtro pré-existente da mesma cadeia com as três abas, não algo introduzido aqui.

**Registrado como observação para o verificador e candidato a todo de acompanhamento — não como gap a corrigir nesta fase.**

## Dívida explícita (sobrevive ao `/gsd:verify-work`)

- **`php artisan test` / `--testsuite=Feature` não termina neste worktree.** Trava numa cascata de timeout de rede de 300s (chamada Guzzle não mockada, alvo não confirmado — candidatos prováveis: Adman, OAuth ML/Google, ou SFTP de grants). Medido primeiro por `150-01` antes de qualquer código desta fase existir; re-confirmado nesta sessão isolando 4 suítes candidatas (todas verdes, 28/28). **O teste exato que dispara o timeout não foi identificado.** Próxima sessão que precisar de `php artisan test` verde: não rodar `--testsuite=Feature` inteiro esperando que termine — isolar por diretório, como `150-BASELINE-TESTES.md` já recomenda.
- **Interação pré-existente entre filtro de etapa e a contagem da aba "Pendências"** (achado nº 2 acima) — candidato a todo de acompanhamento, não corrigido nesta fase.
- `.env` do worktree segue corrigido localmente e não commitado — qualquer `git worktree add` futuro a partir deste ponto herda o `.env` errado de novo se copiado de `ecf_admin`; vale nota para quem abrir um worktree novo desta milestone.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Payload renomeado de `pendencia_aberta` para `tem_pendencia` para não colidir com gate estático de outro plano**
- **Found during:** Task 1 (montagem do payload por empresa)
- **Issue:** Expor a chave `pendencia_aberta` no payload de `/companies` faria o gate estático de `tests/Feature/Phase150/EtapaPendenciaParaleloTest.php` (plano 150-04, D-19) acusar uma leitura direta do flag fora de `Company.php`, mesmo sendo, de fato, uma leitura através do método `pendenciaAberta()`.
- **Fix:** Payload passou a expor `tem_pendencia` (chave de saída, não nome de coluna nem de método), preservando `Company::comPendenciaAberta()`/`pendenciaAberta()` como único caminho de leitura.
- **Files modified:** app/Http/Controllers/CompanyController.php
- **Verification:** `EtapaFiltroListagemTest.php` (9/9) e `EtapaPendenciaParaleloTest.php` (suíte do 150-04, incluída em `tests/Feature/Phase150`) ambos verdes no subset seguro.
- **Committed in:** `7e21aa92` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 — nome de chave de payload redesenhado para não colidir com gate de outro plano da mesma fase)
**Impact on plan:** Correção estritamente local ao nome da chave de saída; nenhuma mudança de comportamento, nenhum scope creep. Os dois achados da verificação humana (seção acima) não são deviations de código — são achados de ambiente e observação de UX, documentados como tal.

## Issues Encountered

- Suite Feature completa (`php artisan test`) segue não-terminável neste worktree por causa pré-existente (não desta fase) — ver "Dívida explícita" acima. Contornado seguindo a decisão nº 2 registrada em "Decisions Made".
- `.env` do worktree apontava para o build do outro checkout, causando tela branca em toda navegação — corrigido pelo orquestrador antes da verificação humana (ver "Achados da verificação humana" nº 1).

## User Setup Required

None - nenhuma configuração de serviço externo.

## Next Phase Readiness

- **Fase 150 está completa** — as 6 Success Criteria do ROADMAP e os 6 requirements (`ETAPA-01`..`ETAPA-06`) estão fechados. Não há plano 150-08.
- Fase 151 (Área Comercial conectada à etapa) depende da Fase 150 e pode começar — o filtro por etapa entregue aqui é a listagem de referência (`/companies`), a listagem do Comercial (COMERC-02) é escopo próprio da 138, não reuso direto deste componente.
- Fase 155 (Onboarding plugado na máquina de estados) é quem troca o critério `em_operacao` (hoje derivado) pela leitura direta de `etapa` — este plano deliberadamente NÃO tocou esse cálculo, só acrescentou filtro ao lado dele.
- Pendências de produção que sobrevivem à Fase 150 inteira, sem relação com este plano especificamente: o backfill em produção e a conferência da aba Empresas antes/depois lá continuam Manual-Only (`150-VALIDATION.md`), e exigem autorização explícita de deploy antes de acontecer — nenhum deploy saiu desta fase.

---
*Phase: 150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0*
*Completed: 2026-09-01*

## Self-Check: PASSED

- FOUND: tests/Feature/Phase150/EtapaFiltroListagemTest.php
- FOUND: app/Http/Controllers/CompanyController.php
- FOUND: resources/js/Pages/Companies/Index.jsx
- FOUND: .planning/phases/150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/150-07-SUMMARY.md
- FOUND commit: 7e21aa92 (Task 1)
- FOUND commit: 4c89cc6d (Task 2)
