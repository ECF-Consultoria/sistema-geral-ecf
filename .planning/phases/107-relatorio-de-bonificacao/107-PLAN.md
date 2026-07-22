# Fase 107 — Relatório de bonificação (MVP)

**Milestone:** v18.0 (Períodos, competência de bônus e variação via Adman) — extensão
**Status:** executing
**Criado:** 2026-07-22
**Depende de:** Fases 91–92 (Desempenho), 100–104 (competência/período), Fase 74 (faixas de bônus)

## Objetivo

Gerar um **relatório de bonificação por competência (mês fechado)** que consolide,
para a equipe de consultoria (analista/estrategista), **quem atingiu o bônus** e a
**nota de cada parâmetro** — a materialização do módulo de desempenho já validado
(NPS, variação de faturamento, variação de margem %).

## Decisões travadas (discuss 2026-07-22)

- **Sem valor em R$ (MVP):** o relatório mostra faixa atingida + notas; RH/gestão
  define o valor a pagar. `bonus_faixas` NÃO ganha coluna de valor nesta fase.
- **Saída:** página no sistema + **export PDF** (barryvdh/laravel-dompdf, já instalado).
- **Escopo:** equipe toda por competência (mês fechado), com filtro por cargo.
- **"Atingiu a meta":** faixa ≠ `sem_bonus` (nota final ≥ 4,00). O relatório
  lista **só quem atingiu**.

## Fonte de dados (reaproveitamento — nada de recomputar)

- **`DesempenhoScoreSnapshot::mensal()`** filtrado por `mes_referencia = competência`
  é a fonte autoritativa (registro imutável do fechamento, populado por
  `desempenho:consolidar-mes`). `breakdown_json` já contém `nota_final`, `faixa_bonus`,
  `componentes` (nps_medio, var_faturamento_pct, var_margem_pct), `pontos_componentes`,
  `score_status`.
- **Fallback:** `DesempenhoScoreService::computeCached($u, $competencia)` quando o
  profissional não tem snapshot na competência (mesma lógica snapshot-first do
  ranking — garante relatório == ranking == auditoria).
- Competência default = `last_closed_month` (`MetricPeriodResolver`), igual à
  auditoria de bônus. Meses selecionáveis = últimos 6.

## Não-objetivos (fora desta fase)

- Cálculo de valor em R$ / tabela de valores por faixa (fase futura).
- Export Excel/CSV (só PDF por ora).
- Relatório individual dedicado (já existe /performance/{user}).

## Tarefas

1. **`RelatorioBonificacaoController`** (mirror de `BonusAuditoriaController`):
   - `index(Request)` → `Inertia::render('Desempenho/RelatorioBonificacao')` com
     competência, competencias_disponiveis, filtro `?cargo=`, e `profissionais`
     (só faixa ≠ sem_bonus), ordenados por nota desc + `ranking_pos`.
   - `pdf(Request)` → renderiza Blade `pdf.relatorio-bonificacao` via dompdf,
     `->download()`/`->stream()`. Mesmos dados do index.
   - Helper `montarLinhas($competencia, $cargo)` compartilhado entre index/pdf
     (snapshot-first + computeCached fallback, filtro de achievers).
2. **Rotas** (`role:admin`): `desempenho.relatorio-bonificacao` (GET) +
   `desempenho.relatorio-bonificacao.pdf` (GET).
3. **Página** `resources/js/Pages/Desempenho/RelatorioBonificacao.jsx`:
   seletor de competência + filtro de cargo, tabela (nome, cargo, NPS,
   var. faturamento %, var. margem %, nota final, faixa), botão "Exportar PDF",
   empty-state quando ninguém atingiu / competência não consolidada.
4. **Blade PDF** `resources/views/pdf/relatorio-bonificacao.blade.php`:
   cabeçalho (competência + data de geração), tabela impressa, rodapé.
5. **Menu**: link "Relatório de bonificação" no grupo Gestão ECF do `AppLayout.jsx`
   (admin-only, mesmo `excludeRoles` da Auditoria de bônus).

## Verificação (goal-backward)

- Página carrega para admin; não-admin recebe 403 (role:admin).
- Lista **apenas** profissionais com faixa ≠ sem_bonus na competência.
- Os números por linha **batem com o ranking** (mesma fonte snapshot/computeCached).
- Endpoint PDF responde `application/pdf`.
- Filtro por cargo restringe a lista.
- Empty-state quando a competência não tem achievers/snapshots.

## Testes (Feature)

- `RelatorioBonificacaoTest`: (a) admin vê a página; (b) só achievers listados
  (fixture com 1 sem_bonus + 1 basico → só o basico aparece); (c) filtro cargo;
  (d) PDF retorna 200 `application/pdf`; (e) não-admin → 403.
