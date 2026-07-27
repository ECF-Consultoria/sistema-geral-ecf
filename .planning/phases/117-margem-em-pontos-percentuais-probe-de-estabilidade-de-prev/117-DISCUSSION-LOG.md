# Phase 117: Margem em pontos percentuais + probe de estabilidade de `prev` - Discussion Log

> **Trilha de auditoria apenas.** Não usar como entrada para agentes de planejamento, pesquisa ou execução.
> As decisões estão em `117-CONTEXT.md` — este log preserva as alternativas consideradas.

**Date:** 2026-07-27
**Phase:** 117-margem-em-pontos-percentuais-probe-de-estabilidade-de-prev
**Areas discussed:** Critério de aprovação do gate, Abrangência do `prev_value`
**Areas offered but not selected:** Como e onde o probe roda, Plano B se `prev` for instável (delegadas ao Claude)

---

## Seleção de áreas

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Critério de aprovação do gate | O que conta como "prev estável"? Tolerância, leituras, intervalo | ✓ |
| Como e onde o probe roda | Comando dedicado vs. estender existente; VPS vs. local; amostra | |
| Plano B se `prev` for instável | Abortar, congelar em snapshot, ou voltar ao cálculo local | |
| Abrangência do `prev_value` | Todas as métricas ou só margem % | ✓ |

---

## Critério de aprovação do gate

### Q1 — O que conta como "`prev` estável"?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Zero flip de nota | Nenhuma empresa muda de faixa da régua entre leituras | ✓ |
| Tolerância absoluta ≤ 0,1 pp | Nenhuma empresa varia mais que 0,1 pp, independente da posição na régua | |
| Identidade exata | `prev` retorna valor idêntico em toda leitura | |

**Escolha:** Zero flip de nota.
**Notas:** Mede o desfecho que importa. Tolerância fixa em pp rejeita ruído inofensivo longe das fronteiras e aceita ruído perigoso em cima delas — `0,98 → 1,04 pp` passaria numa tolerância de 0,1 pp, mas cruza a fronteira `+1` e muda a nota de 3 para 4. Identidade exata foi descartada por risco de reprovar por arredondamento de serialização.

### Q2 — Quantas leituras, espalhadas como?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| 5+ leituras em 24-48h, incluindo janela de sync | Cobre contenção com `[MLB SyncTodasVendas]`/`[MLB SyncPub]` | ✓ |
| 3 leituras com minutos de intervalo | Mesmo desenho da investigação de 23/07 | |
| 2 leituras em dias diferentes | Barato, mas N=2 não separa ruído de tendência | |

**Escolha:** 5+ leituras em 24-48h, com pelo menos uma proposital durante sync concorrente.
**Notas:** Decisão tomada explicitamente para não repetir o erro metodológico de 23/07 — "3 chamadas deram valores idênticos" concluiu *"o dado não flutua"*, o que estava certo sobre lag de assentamento mas cego para rate-limit 429, que depende de horário e concorrência. Quatro dias depois a conclusão virou revert.

### Q3 — Cobertura mínima de `prev` não-nulo?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| ≥ 80%, alinhado ao gate existente | Reusa `MARGEM_COBERTURA_MINIMA = 0.8` | ✓ |
| ≥ 70%, alinhado ao FIXMARG-03 | Alinha com o gate do congelamento mensal | |
| Cobertura não entra no gate | Probe mede só estabilidade de quem tem `prev` | |

**Escolha:** ≥ 80%.
**Notas:** Reusar a constante que já existe no serviço evita dois conceitos concorrentes de "cobertura suficiente" no mesmo arquivo.

### Q4 — Sobre qual população o gate é medido?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Carteiras do Luiz + Danilo, competência fechada | Carteiras do incidente de 23/07, cobertura local verificada | ✓ |
| Só as empresas que oscilaram antes | LUCCAUTO, LYAMDECOR, GARCIA, Hunter, OESTE | |
| Todas as empresas Adman ativas | Cobertura máxima, sem viés de seleção | |

**Escolha:** Carteiras do Luiz (user 3) + Danilo (user 15).
**Notas:** Denominador realista e comparável com o histórico já medido (`+6,83` / `−3,25` / `+8,63`). Amostra só das oscilantes é enviesada para o pior caso — serve para achar problema, não para aprovar. Varredura total foi rejeitada porque ~750 chamadas sequenciais tornariam o próprio probe uma fonte de rate-limit, contaminando a medição.

---

## Abrangência do `prev_value`

### Q1 — `prev_value` em quais métricas?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Nas três métricas | `revenue`, `contribution_margin_value`, `contribution_margin_pct` | ✓ |
| Só em `contribution_margin_pct` | Superfície mínima, só onde a Fase 119 vai ler | |

**Escolha:** Nas três.
**Notas:** A Adman já entrega `.prev` nas três — custo zero de chamadas. Shape uniforme evita reabrir o serviço quando alguma tela quiser exibir "de X para Y" no faturamento.

### Q2 — `diff_pp` em quais métricas?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Só em `contribution_margin_pct` | pp só existe para métrica que já é percentual | ✓ |
| Em todas, como delta absoluto | Uniforme, mas o nome `diff_pp` fica errado em métricas de reais | |

**Escolha:** Só em `contribution_margin_pct`.
**Notas:** `revenue.diff_pp = 7.407,41` seriam reais, não pontos percentuais — o nome mentiria. Uniformizar exigiria renomear para `diff_abs`, e aí a métrica de margem perderia a clareza de "pp", que é o ponto da fase.

### Q3 — `quality` deve sinalizar `diff_pp` ausente?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Sim, contador dedicado | `quality` ganha cobertura de `diff_pp`, `status` intocado | ✓ |
| Não, `status` fica intocado | `diff_pp = null` na linha da métrica já diz tudo | |
| Sim, e `diff_pp` ausente rebaixa o status | `diff_pp` nulo faz a métrica contar como `partial` | |

**Escolha:** Contador dedicado, sem tocar em `status`.
**Notas:** Rebaixar `status` foi rejeitado por consequência estrutural — `status` governa a política de TTL do cache (`partial` → 10 min, `complete` → 1440 min). Empresa sem `prev` cairia em TTL curto permanentemente, martelando a Adman de 10 em 10 minutos numa empresa que nunca terá `prev`.

---

## Claude's Discretion

Áreas oferecidas e não selecionadas pelo usuário, decididas pelo Claude e registradas como D-09 a D-12 no CONTEXT.md:

- **Como o probe roda** — comando artisan novo `adman:probe-margem-prev` (não estender `mlb:inspecionar-adman` nem `adman:warm-diff`); cada leitura persistida com timestamp antes de agregar; execução na VPS contra a Adman real.
- **Plano B se reprovar** — a fase entrega o shape de qualquer jeito (aditivo, não quebra ninguém); o que fica bloqueado é a Fase 119 consumir `diff_pp` para nota. Congelamento de `prev` e volta ao cálculo local ficam como fases próprias, escolhidas conforme o modo de falha observado.

O usuário revisou essas decisões antes do fechamento e não objetou.

---

## Deferred Ideas

- Congelar `percentageMargin.prev` em snapshot diário próprio — só se o probe reprovar por instabilidade intermitente
- Reintroduzir cálculo local de margem a partir de `adman_metrics` — revertido em 24/07; só volta à mesa se o probe reprovar E a Adman se mostrar a fonte errada
- Recalibrar a régua de margem para pp — travado em D2 da milestone; eventual pauta de diretoria após a medição da Fase 121
- Expor `diff_pp`/`prev_value` na UI — Fase 123
- **Decidir o freeze de junho/2026 (prazo 31/07 14h BRT)** — decisão de negócio imediata, fora desta fase e desta milestone; permanece aberta em `.planning/todos/pending/metrica-margem-bonus-fragil.md`
