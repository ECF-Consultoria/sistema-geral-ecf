---
phase: 120-agrega-o-do-profissional-feature-flag-v21-0
plan: 03
subsystem: desempenho
tags: [feature-flag, bifurcacao, agregacao, laravel, phpunit]

# Dependency graph
requires:
  - phase: 120-01
    provides: feature flag `metrics.performance_company_first_score` (desligada), parâmetro `$incluirEmpresasScore`, `cacheKey()` em v14
  - phase: 120-02
    provides: shadow ligado com garantia em `desempenho:consolidar-mes`/`desempenho:warm-cache`
provides:
  - "computeNotaFinalPorEmpresa()/computeScoreStatusPorEmpresa() — os dois métodos novos que a flag ligada usa"
  - "if de bifurcação em compute() — escolhe qual par (legado × novo) alimenta nota_final/score_status"
  - "primeiro dado numérico concreto da divergência régua-da-média × média-das-réguas (4 pares LEGADO×NOVO congelados)"
affects: [121-comparador-antigo-x-novo, 122-persistencia-empresas-score, 123-telas]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Bifurcação por if no orquestrador (compute()), nunca função híbrida — as duas árvores de chamada (legado × novo) ficam fisicamente separadas"
    - "Mirror test: chamar compute() duas vezes na MESMA fixture (flag off, depois flag on) para congelar o par LEGADO×NOVO honesto, em vez de comparar contra literal potencialmente obsoleto do teste original"
    - "Mock de serviço colaborador (CompanyScoreService::computeEmpresasScore) via Mockery partial para testar a camada de agregação isoladamente do cálculo por-empresa (já provado na Fase 119)"

key-files:
  created:
    - tests/Feature/Phase120/AgregacaoProfissionalTest.php
  modified:
    - app/Services/DesempenhoScoreService.php
    - tests/Feature/DesempenhoShopeeScoreTest.php

key-decisions:
  - "Constante COBERTURA_MINIMA_SCORE_STATUS=0.7 duplicada de propósito (não importada) de ConsolidarMesDesempenho::MARGEM_COBERTURA_MINIMA_CONGELAMENTO (privada lá) — docblock amarra os dois valores para o sistema não ganhar dois conceitos concorrentes de cobertura suficiente."
  - "D-01 mantido como decidido no 120-CONTEXT.md: empresa partial/sem_fonte/sem_dados fica FORA do denominador da média, nunca entra com nota_empresa_parcial — a guarda de cobertura de 70% é o que impede abuso de carteira mal coberta."
  - "Testes 1-5 de AgregacaoProfissionalTest mockam CompanyScoreService::computeEmpresasScore() em vez de montar fixtures financeiras reais — a lógica por-empresa (fonte vencedora, placeholder Shopee, taxonomia de status) já foi provada pela Fase 119; esta suíte testa só a camada de agregação, com controle total sobre o shape de entrada."
  - "Mirror tests da Task 3 comparam compute() com flag off × flag on na MESMA fixture (não contra o literal hardcoded do teste original) — 3 dos 4 testes originais já falham hoje por um achado pré-existente e fora de escopo (hotfix de 2026-07-24, documentado no 120-01-SUMMARY), então comparar contra o literal deles teria herdado uma expectativa obsoleta."

requirements-completed: [AGRE-01, AGRE-05, AGRE-06]

# Metrics
duration: ~2h
completed: 2026-07-30
---

# Phase 120 Plan 03: A bifurcação — Summary

**`compute()` ganha um `if` que escolhe entre o par legado (`computeNotaFinal`/`computeScoreStatus`, intocados) e o par novo (`computeNotaFinalPorEmpresa`/`computeScoreStatusPorEmpresa`) conforme a feature flag — que continua `false`. 11 testes novos (7 de agregação + 4 espelhos) provam AGRE-01/05/06 e capturam, pela primeira vez, o valor numérico real da divergência régua-da-média × média-das-réguas herdada da Fase 119.**

## FASE 120 FECHADA — 3/3 planos (2026-07-30)

Este plano fecha a Fase 120. A flag `metrics.performance_company_first_score` continua `false` em produção — nada nesta wave, nem em nenhuma das 3 waves da fase, a liga.

## O que foi entregue

### Task 1 — `computeNotaFinalPorEmpresa`, `computeScoreStatusPorEmpresa` e o `if` de bifurcação (AGRE-01/05/06)

`app/Services/DesempenhoScoreService.php`:

- **Constante `COBERTURA_MINIMA_SCORE_STATUS = 0.7`** — declarada no topo da classe com docblock que amarra o valor ao mesmo patamar de `ConsolidarMesDesempenho::MARGEM_COBERTURA_MINIMA_CONGELAMENTO:76` (aquela constante é `private` no comando; o valor aqui é duplicado de propósito, não importado). Registrado que o usuário não fixou este número — é decisão de planejamento (D-03 do `120-CONTEXT.md`), fácil de sobrepor.
- **`computeNotaFinalPorEmpresa(Collection $empresasScore): ?float`** — filtra `status === 'complete'`; `null` se nenhuma sobrar; senão a média de `nota_empresa` das completas, arredondada a 2 casas. Método NOVO e SEPARADO de `computeNotaFinal()`.
- **`computeScoreStatusPorEmpresa(Collection $empresasScore): string`** — `blocked` só quando `nota_empresa_parcial` é `null` em TODAS; senão cobertura = completas/total (universo inteiro, incluindo `sem_fonte`); `official` se cobertura ≥ 0.7, senão `partial`. Método NOVO e SEPARADO de `computeScoreStatus()`.
- **O `if` de bifurcação** substitui as duas linhas antigas (`$nota = computeNotaFinal(...)`; `$scoreStatus = computeScoreStatus(...)`) por um `if (config('metrics.performance_company_first_score') && $empresasScore !== null) { ... } else { ... }` — nunca uma função híbrida. A linha D-91-01 (`if ($scoreStatus === 'blocked') { $nota = null; }`) permanece exatamente onde estava, valendo para os dois ramos.
- **Nenhum outro trecho do arquivo mudou.** `computeNotaFinal`, `computeScoreStatus` e `margemPontos` permanecem com assinatura e corpo intocados — nunca recebem `$empresasScore` nem leem a flag.

### Task 2 — `AgregacaoProfissionalTest` (AGRE-01/05/06)

`tests/Feature/Phase120/AgregacaoProfissionalTest.php`, 7 testes:

1. `nota_final` é exatamente a média das `nota_empresa` das `complete` (3 empresas distintas, média calculada no próprio teste a partir de `empresas_score` — não literal solto).
2. Empresa `partial` com `nota_empresa_parcial` MAIOR (4,80) que a completa (4,53) fica fora do denominador — `nota_final` é a nota da completa, não a média das duas (D-01).
3. Cobertura 30% (3 de 10) → `partial`, sem zerar a nota; cobertura 80% (8 de 10) → `official`.
4. Só-Shopee: todas as empresas `complete`, `official`, sem nenhum `if` especial no serviço.
5. `blocked` só quando `nota_empresa_parcial` é `null` em todas.
6. Escopo da carteira (T-120-06) e invalidação por competência (T-120-07): empresa de outro profissional e empresa invalidada ausentes de `empresas_score`.
7. Flag nasce `false` por default (T-120-03), sem nenhum override de config.

Testes 1-5 mockam `CompanyScoreService::computeEmpresasScore()` via Mockery partial (`$this->mock()`) para controlar precisamente o shape de `$empresasScore` — a lógica por-empresa já foi provada pela Fase 119 (`CompanyScoreServiceFonteTest`/`CompanyScoreServiceStatusTest`); esta suíte testa a camada de agregação. Testes 6-7 usam fixture 100% real, sem mock.

### Task 3 — Cenários espelho no `DesempenhoShopeeScoreTest` (D-05, gate nº 3)

4 métodos NOVOS acrescentados (nunca reescritos — `git diff | grep -c '^-[^-]'` = 0) para os 4 testes que dependem de `margemPontos()`. Cada espelho chama `compute()` DUAS vezes na MESMA fixture do teste original (mesmo empresa/setup) — uma com a flag desligada (`$legado`), uma com a flag ligada (`$novo`) — e congela os dois resultados como literais capturados em execução (2026-07-30). Ver seção de pares abaixo.

## Reafirmação obrigatória: a flag permanece `false`

- `config('metrics.performance_company_first_score')` confirmado `false` por padrão em `config/metrics.php` (sem entrada em `.env`/`.env.example` — `grep -rn "PERFORMANCE_COMPANY_FIRST_SCORE" .env .env.example` = zero linhas).
- **Dois pré-requisitos, nenhum satisfeito nesta fase, para ligar em produção:**
  1. **GATE MPP-04 aprovado** — hoje `reprovado` (`cobertura_prev = 0.6415`, 5 rodadas, apurado 2026-07-29 11:56 BRT).
  2. **Delta da Fase 121 aceito pelo usuário** — a Fase 121 vai quantificar exatamente quanto a nota de cada profissional muda ao ligar a flag; ativar antes disso muda o número que paga bônus sem essa evidência.
- Testes verdes (18/18 em `--filter=Phase120`) **não liberam a ativação** — são prova de que o código funciona como desenhado, não decisão de negócio.

## O risco régua-da-média × média-das-réguas — primeiros números concretos

Herdado do `119-04-SUMMARY.md`: o invariante do docblock de `margemPontos()` — *"só-performance devolve exatamente `reguaMargem($varMargemReal)`, regressão zero"* — não vale no caminho novo. Ligar a flag muda notas; quantificar quanto é a Fase 121. Os 4 espelhos da Task 3 são o primeiro dado numérico concreto dessa divergência, capturado ao vivo (2026-07-30) chamando `compute()` duas vezes na mesma fixture:

| Cenário | LEGADO nota_final / score_status | NOVO nota_final / score_status | Leitura |
|---|---|---|---|
| Só-performance (1 empresa, margem real indisponível na janela) | `2.50` / `partial` | `null` / `partial` | **D-01 é mais rigoroso que o agregado legado.** O legado tolera o componente margem ausente e tira a média só dos 2 presentes (nps+faturamento); o novo exige que a EMPRESA seja `complete` (os 3 componentes) antes de contar em qualquer média — com 1 única empresa incompleta, não há nada pra promediar. |
| Só-Shopee (1 empresa) | `2.33` / `official` | `2.33` / `official` | **Idênticos.** Com uma única empresa, não há divergência de granularidade possível (nada para "promediar diferente") — regressão-zero por construção, não por desenho de régua. |
| Misto (1 Adman + 1 Shopee) | `2.33` / **`official`** | `2.33` / **`partial`** | **A divergência mais reveladora.** `nota_final` coincide numericamente (2.33 nos dois), mas `score_status` diverge: o blend `margemPontos()` legado NUNCA devolve `null` enquanto houver pelo menos 1 empresa Shopee no denominador — o placeholder "tapa o buraco" da margem real ausente da empresa Adman e o agregado parece 100% coberto. A cobertura por empresa do caminho novo EXPÕE que só 1 das 2 empresas é `complete` (50% < 70% → `partial`). |
| Invalidação (idem + 1 Shopee invalidada) | `2.33` / **`official`** | `2.33` / **`partial`** | Mesma divergência do cenário misto — mais a prova de que a empresa invalidada continua ausente de `empresas_score` nos dois ramos. |

**Achado adicional, não previsto pelo plano:** em todos os 4 espelhos, `componentes.var_margem_pct`/`pontos_componentes.margem` (os 4 componentes legados, AGRE-04) saem **idênticos** entre `$legado` e `$novo` — confirmado por asserção direta (`assertSame`) em cada mirror, não por literal. Isso é esperado por construção (a bifurcação só troca `nota_final`/`score_status`), mas os VALORES em si (frequentemente `null`) refletem o achado pré-existente do hotfix de 2026-07-24 documentado no `120-01-SUMMARY.md` — não uma descoberta nova desta task. Os 3 testes originais que dependiam do valor teórico pré-hotfix (2.80%/4.0/2.50) continuam falhando pela MESMA causa pré-existente, já contabilizada na baseline de 14 falhas — não é regressão desta task, e os literais desses 3 testes originais **não foram alterados**.

## Verificação

| Gate | Resultado |
|---|---|
| `--filter=AgregacaoProfissionalTest` | **7/7 verde** |
| `--filter=Phase120` | **18/18 verde** (7 + 5 + 6) |
| `--filter=DesempenhoShopeeScoreTest` | 11/11 — os 4 espelhos verdes; os 3 originais que já falhavam continuam falhando pela MESMA causa pré-existente (achado do 120-01-SUMMARY), não regressão |
| `git diff -- tests/Feature/DesempenhoShopeeScoreTest.php \| grep -c '^-[^-]'` | **0** — diff só com linhas adicionadas |
| `--filter=Desempenho` (regressão ampla) | **14 failed / 98 passed** — mesma baseline de 14 falhas pré-existentes. Subiu de 94 (Plano 02) para 98 passed: os +4 são exatamente os 4 espelhos novos em `DesempenhoShopeeScoreTest` (cujo nome de classe casa com o filtro `Desempenho`); `AgregacaoProfissionalTest` não casa com este filtro (nome de classe não contém "Desempenho"), por isso seus 7 testes não aparecem nesta contagem — são cobertos separadamente por `--filter=Phase120`/`--filter=AgregacaoProfissionalTest` |
| `grep -n "config('metrics.performance_company_first_score')" app/Services/DesempenhoScoreService.php` | 2 ocorrências funcionais (linha do shadow, Plano 01; linha da bifurcação, Task 1 deste plano) + 1 menção em docblock pré-existente — nenhuma dentro de método legado (`computeNotaFinal`/`computeScoreStatus`/`margemPontos` confirmados sem referência à flag nem a `$empresasScore`) |
| `grep -rn "PERFORMANCE_COMPANY_FIRST_SCORE" .env .env.example` | 0 linhas |
| `config('metrics.performance_company_first_score')` | `false` confirmado (sem override) |
| Assinaturas legadas | `computeScoreStatus(array $contadores, ?float $varFat, ?float $margemPontos)` e `computeNotaFinal(?float $nps, ?float $varFat, ?float $margemPontos)` — idênticas, confirmado via `git diff` sem linhas removidas nessas declarações |

## Deviations from Plan

Nenhuma. O plano previa comparar os 4 espelhos contra os literais teóricos dos testes originais (2.80/4.0/3.00 para "só performance", 2.80/2.50 para "misto"/"invalidação") — na prática, 3 desses literais já estão obsoletos por um achado pré-existente e documentado (120-01-SUMMARY), então os espelhos comparam `compute()` com flag off × flag on na MESMA fixture (par honesto, capturado ao vivo) em vez de contra o literal potencialmente obsoleto. Isto não é um desvio do OBJETIVO do gate nº 3 (provar equivalência dos componentes legados entre os dois ramos, D-05) — é uma adaptação de MÉTODO que produz uma prova mais forte (auto-consistência via `assertSame`, não dependência de um número que pode já estar errado por razões alheias a esta fase). Documentado aqui por transparência, não como Rule 1-4.

## Diferido de propósito

- **Aposentar `margemPontos()` e unificar `reguaFaturamento`/`reguaMargem`** — só quando a flag virar permanente (débito da C-03 da Fase 119).
- **Persistir `empresas_score` de forma estruturada** — Fase 122.
- **Exibir a lista de empresas com nota na UI** — Fase 123.
- **Ligar a flag em produção** — depende do GATE MPP-04 aprovar (hoje `reprovado`) e do delta da Fase 121 ser aceito pelo usuário.

## Known Stubs

Nenhum.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano — T-120-03 (elevação via flag antes do gate), T-120-06 (escopo da agregação), T-120-07 (empresa invalidada) e T-120-10 (rastreabilidade) já cobrem tudo que foi tocado; todos com mitigação testada (teste 7 da Task 2 para T-120-03, teste 6 da Task 2 + espelho da invalidação da Task 3 para T-120-06/07, `empresas_score` no payload para T-120-10).

## Task Commits

1. **Task 1: computeNotaFinalPorEmpresa, computeScoreStatusPorEmpresa e o if de bifurcação** — `accd5fb4` (feat)
2. **Task 2: AgregacaoProfissionalTest — prova a agregação, o denominador, a cobertura e o escopo** — `c9e1b945` (test)
3. **Task 3: Cenários espelho no DesempenhoShopeeScoreTest (D-05, gate nº 3)** — `d60b36c7` (test)

## Self-Check

- `app/Services/DesempenhoScoreService.php` — FOUND (modificado, commit `accd5fb4`)
- `tests/Feature/Phase120/AgregacaoProfissionalTest.php` — FOUND (criado, commit `c9e1b945`)
- `tests/Feature/DesempenhoShopeeScoreTest.php` — FOUND (modificado, commit `d60b36c7`)
- Commit `accd5fb4` — FOUND em `git log --oneline`
- Commit `c9e1b945` — FOUND em `git log --oneline`
- Commit `d60b36c7` — FOUND em `git log --oneline`

## Self-Check: PASSED

---

**Dependency graph:**
- **requires:** `app/Services/Desempenho/CompanyScoreService.php` (Fase 119), feature flag + shadow (Plano 01), roteamento do shadow (Plano 02)
- **provides:** bifurcação completa de `nota_final`/`score_status` atrás da flag; primeiro dado numérico da divergência régua-da-média×média-das-réguas
- **affects:** Fase 121 (comparador antigo×novo, parte destes 4 pares), Fase 122 (persistência de `empresas_score`), Fase 123 (telas)

**Tech stack:** nenhuma dependência nova (`composer.json`/`package.json` inalterados).

**Metrics:**
- Duration: ~2h
- Tasks: 3/3
- Files touched: 3 (1 criado, 2 modificados)
- Completed: 2026-07-30

---
*Phase: 120-agrega-o-do-profissional-feature-flag-v21-0*
*Completed: 2026-07-30*
