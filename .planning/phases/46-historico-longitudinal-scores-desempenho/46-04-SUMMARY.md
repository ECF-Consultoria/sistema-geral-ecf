---
plan: 46-04
status: complete
type: human-uat
completed_at: 2026-06-30
---

# Plan 46-04 — SUMMARY

UAT humano em produção APROVADO em 2026-06-30 pré-cron. Detalhes em [46-04-UAT.md](46-04-UAT.md).

## Phase 46 fechada

- Plan 46-01: ✅ migration + Model `DesempenhoScoreSnapshot` + comando `desempenho:snapshot-scores` + schedule **13:30 BRT** (8/8 testes verdes)
- Plan 46-02: ✅ delta_vs_ontem + delta_vs_semana_passada no ranking + endpoint `GET /api/performance/{user}/evolucao` (11/11 testes verdes)
- Plan 46-03: ✅ ScoreDelta inline + EvolucaoDrawer com Recharts (linha sólida user + pontilhada mediana grupo); build 17.82s verde
- Plan 46-04: ✅ UAT APROVADO em prod pré-cron

## Descoberta-chave durante o research

A metodologia justa do briefing `metodologia-desempenho-carteira.md` JÁ estava implementada em `PortfolioScoreService` (quick 260623). Phase 46 simplificou drasticamente: só persiste + compara + visualiza. Sem refatoração de score.

## Próximas phases destravadas

- **Phase 47** (scoring por função com balanceamento por volume) — agora tem persistência de breakdown para mostrar impacto "antes/depois" de mudança de pesos
- **Phase 50** (gamificação OAuth ML) — independente, segue disponível

## Curva de povoamento orgânico

Snapshot só popula a partir do deploy. Operador verifica organicamente:
- 2026-07-01 após 13:30 BRT — 1º snapshot, drawer mostra 1 ponto
- 2026-07-02 após 13:30 BRT — 2º snapshot, micro-deltas coloridos pela 1ª vez
- 2026-07-07 — janela `delta_vs_semana_passada` começa a popular

Backfill artificial intencionalmente FORA do escopo (geraria dados enganosos).
