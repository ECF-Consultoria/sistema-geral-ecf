# Junção das contas do Danilo (35 → 15) — ADIADA em 2026-10-01

**Fase:** 159 — plano `159-08` não executado.
**Decisão do usuário (01/10):** adiar a junção. O código de dois cargos já está em produção (`c7a58b3b`).

## Por que parou

`usuarios:unificar-contas --de=35 --para=15 --a-partir=2026-09` sai com exit 1: a competência
2026-08 não tem snapshot mensal para o user 15. A trava está certa — mover a carteira recalcularia
agosto. A causa é o `consolidar-mes` quebrado em produção desde julho pelo unique legado de
`desempenho_score_snapshots` (learnings §10.1): julho e agosto não fecharam para quase ninguém, e
setembro (31/10 14:00) vai falhar igual.

## Para retomar (nesta ordem)

1. Decisão do usuário sobre a correção do fechamento (fase GSD própria — mexe em consolidação de
   bônus): subir `2026_08_31_150000_drop_unique_legado_desempenho_score_snapshots` (hoje só arquivo
   não commitado no checkout principal), conferir `SHOW INDEX`, consolidar 2026-07 e 2026-08,
   conferir por `desempenho:verificar-consolidacao --mes=YYYY-MM --json` (exit code) e por contagem.
2. Medir de novo: `desempenho:auditar-ramo-legado --user=15 --user=35 --mes=2026-09` (depois de
   31/10 sai do "inconclusivo") e o dry-run da junção.
3. Decidir com o usuário: desativar o 35 tira ele das telas de meses fechados (`users.active`).
4. `--apply` com o usuário presente; conferir por reconsulta (contagens de referência em
   `/root/juncao_danilo_antes_20261001_1422.txt`).
5. **Segundo passe da etapa de NPS antes do `consolidar-mes` da competência que fechar unificada** —
   respostas que chegarem antes do `--apply` nascem no 35.

Se 2026-09 não puder mais fechar unificada, o corte passa a ser `--a-partir=2026-10` (exige 2026-09
consolidado).
