# Fase 116 — Itens Deferidos (fora de escopo)

Registrado durante a execução do Plan 02 (2026-07-27). Estes achados foram
descobertos ao rodar os gates de regressão (`--filter=Desempenho`,
`--filter=Nps`, `--filter=Bonus`) e são **PRÉ-EXISTENTES** — confirmados via
`git diff` vazio nos arquivos envolvidos (nenhum tocado por este plano) e/ou
reprodução idêntica em execução isolada (`--filter=<ClasseDoTeste>`), sem
nenhum arquivo da Fase 116 carregado.

## 1. Baseline de 14 falhas em `--filter=Desempenho` (var_margem_pct/AdmanMetricDiffService)

Já documentado no prompt de execução do Plan 02 e no `116-01-SUMMARY.md`.
Causa raiz: commit `25a958b3` de outra sessão paralela (fallback de billing em
`app/Services/Metrics/AdmanMetricDiffService.php`), feito no mesmo dia. Arquivo
explicitamente fora de escopo — não editado.

Confirmado estável em 3 execuções do Plan 02 (antes e depois do wiring do
ramo C): sempre **14 failed / 90 passed** em `--filter=Desempenho` (as 90
incluem os 7 testes novos de `NpsFloorDesempenhoTest`).

## 2. `V18/JanelaNpsBonusTest::test_competencia_fechada_le_nps_de_m_mais_1` — instabilidade de margem

- **Sintoma:** `nota_final` esperado 3.32, obtido 3.99 (delta = variação de
  ~2 pontos em `margemPontos`; a parte de NPS do teste, 4.97, bate certinho —
  não é o ramo novo desta fase que está errado).
- **Causa:** mesma instabilidade documentada em
  `.planning/debug/project_adman_margem_diff_instavel_bonus` (aberto
  2026-07-23, "NÃO é regressão da Fase 109") — o `.diff` nativo da Adman
  retorna valores diferentes a cada recompute para o mesmo mês fechado.
- **Confirmado pré-existente:** `git diff --stat -- tests/Feature/V18/JanelaNpsBonusTest.php`
  vazio (arquivo não tocado por este plano); falha reproduzida em execução
  100% isolada (`--filter=JanelaNpsBonusTest`, sem nenhum outro arquivo da
  Fase 116 carregado).
- **Ação:** não corrigido — `AdmanMetricDiffService` está fora do escopo
  desta fase (instrução explícita do executor).

## 3. `Phase31NpsSubmitTest` / `Phase69/NpsPhase69IntegrationTest` — `expires_at` de survey manual

- **Sintoma:** `expires_at` de survey manual (`generate()`) sai ~3-4 dias
  menor do que `now()->addDays(7)` esperado pelo teste.
- **Confirmado pré-existente e NÃO relacionado à Fase 116:**
  - `git diff --stat` vazio para `tests/Feature/Phase31NpsSubmitTest.php`,
    `tests/Feature/Phase69/NpsPhase69IntegrationTest.php` e
    `app/Http/Controllers/NpsController.php` — nenhum tocado por este plano.
  - Falha reproduzida em execução 100% isolada
    (`--filter=Phase31NpsSubmitTest`, sem nenhum outro arquivo carregado).
- **Ação:** não investigado a fundo (fora do escopo do Plan 02 — a rota de
  disparo manual do NPS, `NpsController::generate()`, não foi tocada por
  nenhuma task deste plano). Recomenda-se abrir debug dedicado se persistir
  quando a Fase 116 fechar.

## 4. `V18/ConsolidarMesJanelaNpsTest` (2 falhas)

Já documentado no `116-01-SUMMARY.md` como pré-existente (congelamento de
snapshot mensal via `desempenho:consolidar-mes`). Confirmado novamente aqui
— `git diff --stat` vazio para o arquivo.

---

**Resumo para o verificador:** nenhum destes 4 itens foi causado pelas
mudanças do Plan 02 (`app/Services/DesempenhoScoreService.php`,
`NpsImputationService` consumido via `notasImputadas()`, ou os testes novos/
modificados listados no `116-02-SUMMARY.md`). Todos confirmados por
`git diff` vazio no arquivo afetado e/ou reprodução isolada.
