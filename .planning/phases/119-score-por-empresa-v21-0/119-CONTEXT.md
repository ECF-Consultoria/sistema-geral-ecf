# Phase 119: Score por empresa - Context

**Gathered:** 2026-07-28
**Status:** Ready for planning

<domain>
## Phase Boundary

Esta fase produz **o fato por empresa**: uma linha por `(user_id, company_id)` com os três componentes já pontuados e a `nota_empresa` calculada. A régua de faturamento passa a ser aplicada **por empresa** antes de qualquer média, e a de margem passa a ler `margem_var_pp`.

**NÃO está nesta fase:** agregar a nota do profissional nem ligar feature flag (Fase 120), comparar antigo × novo (Fase 121), persistir (Fase 122), telas (Fase 123).

**A fase é ADITIVA.** Ninguém consome `CompanyScoreService` ainda — `DesempenhoScoreService` continua calculando exatamente como hoje. Nenhum número de produção muda.

</domain>

<blocking_dependency>
## ⚠️ Bloqueio de execução — GATE MPP-04

O `Depends on` desta fase no ROADMAP exige, além das Fases 117 e 118 executadas, o **GATE MPP-04 APROVADO pelo usuário**.

Esta fase é a primeira a **consumir `diff_pp` para calcular nota**. Enquanto o probe de estabilidade de `percentageMargin.prev` não tiver rodado na VPS e o veredito não tiver sido aprovado, **a execução fica bloqueada** — planejar é permitido, executar não.

Estado em 2026-07-28: apenas a leitura L1 (`pico_tarde`) registrada; faltam ≥4, incluindo a obrigatória na janela 11:00-12:00 BRT. Se o veredito vier `reprovado` ou `instrumentacao_suspeita`, esta fase muda de forma — não assuma aprovação.

</blocking_dependency>

<decisions>
## Implementation Decisions

### Nota da empresa quando falta componente

- **D-01 · A linha reporta DOIS números, não um.** `nota_empresa` (estrita — `null` se faltar qualquer um dos 3 componentes) **e** `nota_empresa_parcial` (média dos componentes presentes), mais `componentes_presentes` (int), `status` e `quality.motivos`.
  **Razão:** a Fase 119 é aditiva e informativa; quem decide política de denominador é a Fase 120, onde essa decisão já está registrada como aberta. Reportar só a estrita jogaria fora a informação de que a Fase 121 precisa para medir impacto; reportar só a parcial misturaria silenciosamente empresa completa com incompleta numa média que paga bônus — uma empresa com 2 componentes bons tiraria nota **maior** que uma completa, sem nada sinalizando.

### Empresa Shopee

- **D-02 · Empresa Shopee conta como `complete`**, com `quality.margin_source = 'placeholder_shopee'`.
  **Razão:** a Fase 109 travou que profissional só-Shopee **não** pode cair em `blocked`/`partial` por ausência de margem. Marcar a linha como parcial contradiria a trava e puniria duas vezes — o placeholder `1.0` já puxa a nota para baixo por si só.

### Empresa sem fonte financeira

- **D-03 · Permanece listada em `empresas_score`**, com `nota_empresa = null`, `nota_empresa_parcial` = a própria nota de NPS, `componentes_presentes = 1`, `status = 'sem_fonte'` e `quality.motivos = ['sem_fonte_financeira']`.
  **Razão:** preserva a auditabilidade que a Fase 121 vai precisar para explicar deltas — sem isso não dá para distinguir "não tem fonte financeira" de "não está na carteira". O denominador fica para a Fase 120.

### Claude's Discretion

- **D-04 · O blend `margemPontos()` da Fase 109 fica INTOCADO nesta fase.** Área não selecionada pelo usuário; decisão minha.
  `DesempenhoScoreService::margemPontos()` (linha ~1348) continua sendo o caminho vivo enquanto a flag da Fase 120 estiver desligada. O caminho novo simplesmente não o usa: no modelo por empresa a ponderação emerge naturalmente da média das notas — empresa Shopee entra com `margem_pontos = 1.0` na própria linha.
  **A aposentadoria é decisão da Fase 120**, quando a flag existir. Aqui ele nem é tocado (gate de aditividade).

- **D-05 · Assinatura e local do `CompanyScoreService`** — o planner decide, coerente com os vizinhos (`app/Services/Desempenho/` já hospeda o `NpsPorEmpresaService` da Fase 118).

</decisions>

<risks>
## Risco registrado — a aritmética muda, e os testes da Fase 109 sabem disso

Hoje `margemPontos()` aplica a régua **uma vez** sobre a média agregada e depois pondera com os placeholders Shopee por contagem:

```php
$pReal = reguaMargem($varMargemReal);              // régua sobre a MÉDIA
$num   = $pReal * $nComMargemReal + 1.0 * $nShopee;
$den   = $nComMargemReal + $nShopee;
```

No modelo novo a régua roda **por empresa** e a média vem depois. **Régua-da-média ≠ média-das-réguas** — é justamente o ponto da milestone (D3), mas tem consequência concreta:

O docblock de `margemPontos()` declara como invariante testado em `DesempenhoShopeeScoreTest`:
> *"Só-performance (`$nShopeePlaceholder=0`) → devolve exatamente `reguaMargem($varMargemReal)` — IDÊNTICO ao comportamento pré-Fase 109 (regressão zero)."*

Esse invariante **não vai valer** no caminho novo. Isso é esperado e intencional, mas:
- **na Fase 119 não é problema** — a fase é aditiva, o caminho antigo segue intocado e `DesempenhoShopeeScoreTest` continua verde;
- **na Fase 120 vira problema real** quando a flag ligar. O plano da 120 precisará decidir se `DesempenhoShopeeScoreTest` ganha cenários novos para o modo flag-ligada ou se os invariantes são reescritos.

**Registrar isso no SUMMARY da 119** para a 120 não descobrir na hora.

</risks>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plano canônico e requirements
- `plano-implementacao-desempenho-por-empresa.md` §3.1 — contrato da linha por empresa
- `plano-implementacao-desempenho-por-empresa.md` §3.2, §3.4, §3.5 — fórmula, dados ausentes, Shopee
- `plano-implementacao-desempenho-por-empresa.md` §4 "Fase 3" — passos sugeridos do `computeEmpresasScore`
- `.planning/REQUIREMENTS-v21.md` — EMPS-01..EMPS-07 e as decisões D1-D6 da milestone

### Decisões anteriores que esta fase toca
- `.planning/phases/109-.../109-CONTEXT.md` `<decisions>` — placeholder de margem Shopee = 1.0 e a trava de que só-Shopee não cai em `blocked`/`partial`
- `.planning/phases/117-.../117-CONTEXT.md` — D-01..D-12, o gate MPP-04 e a semântica de `diff_pp`
- `.planning/phases/118-.../118-CONTEXT.md` — D-01..D-06 do NPS por empresa; esta fase **consome** `NpsPorEmpresaService`
- `.planning/phases/118-.../118-01-SUMMARY.md` — shape exato devolvido por `notasNpsPorEmpresa()`

### Código
- `app/Services/DesempenhoScoreService.php:1130` e `:1204` — as **duas** chamadas ao dispatcher que o EMPS-05 manda unificar
- `app/Services/DesempenhoScoreService.php:1290` `reguaFaturamento()` · `:1311` `reguaMargem()` · `:~1348` `margemPontos()`
- `app/Services/Metrics/MetricDiffDispatcher.php:34` — `compute(Company $company, array $periodo, string $source): array`
- `app/Services/Portfolio/CarteiraContextService.php:103,245` — `has_financial_source` / `financial_source` / `financial_metrics_eligible`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`NpsPorEmpresaService::notasNpsPorEmpresa()` (Fase 118)** — já entrega a nota de NPS por `company_id`, com `origem`, `por_ramo`, `houve_survey` e `consolidado`. É o componente NPS pronto; esta fase só o consome.
- **`MetricDiffDispatcher::compute($company, $periodo, $source)`** — roteia Adman × Shopee. Devolve `metrics.{revenue,contribution_margin_value,contribution_margin_pct}` já com `prev_value`/`diff_pp` desde a Fase 117.
- **`CarteiraContextService::forUser()`** — universo com `has_financial_source`, `financial_source`, `financial_metrics_eligible`. É a fonte para a D-03 (`sem_fonte`) e para a resolução Adman-vence-Shopee do EMPS-06.
- **`reguaFaturamento()` / `reguaMargem()`** — as réguas existem e não mudam de valor; o que muda é **onde** são aplicadas. `reguaMargem` passa a receber `margem_var_pp` (D2 da milestone), nunca `diff_pct`.

### Established Patterns
- **Dispatcher chamado 2× hoje** — `computeVarFaturamento()` (linha 1130, lê `revenue.diff_pct`) e `computeVarMargem()` (linha 1204, lê o resultado inteiro). O EMPS-05 exige **uma** chamada por empresa; o resultado alimenta os dois componentes.
- **`quality` como veículo de auditoria** — padrão já usado em `AdmanMetricDiffService` (`status`, `source`, `computed_at`, `diff_pp_disponivel`). A linha por empresa deve seguir o mesmo idioma.
- **Fase aditiva com gate de hash** — as Fases 117 e 118 usaram `sha256sum` de `DesempenhoScoreService.php` em toda task. Manter o padrão aqui.

### Integration Points
- **`DesempenhoScoreService` não é modificado nesta fase.** O `CompanyScoreService` nasce ao lado e é exercitado só por testes.
- **Fase 120** é quem injeta o serviço novo no cálculo, atrás de flag.

</code_context>

<specifics>
## Specific Ideas

- Caso âncora do plano §5.4, para virar teste: NPS 4,6 · faturamento +8% → 5 · margem +3,2 pp → 4 ⇒ `nota_empresa = 4,53`.
- Caso que prova a diferença de granularidade (§5.4): empresa A com faturamento −20% (pontos 1) e empresa B com +2% (pontos 4) ⇒ regra nova dá `(1+4)/2 = 2,5`; regra antiga daria `reguaFaturamento(−9%) = 1`.
- Caso da D-01: empresa com NPS 4,6, faturamento 5 e margem ausente ⇒ `nota_empresa = null`, `nota_empresa_parcial = 4,80`, `componentes_presentes = 2`, `status = 'partial'`.
- Caso da D-02: empresa Shopee com NPS 4,2 e faturamento 4 ⇒ `nota_empresa = (4,2+4+1,0)/3 = 3,07`, `status = 'complete'`, `quality.margin_source = 'placeholder_shopee'`.

</specifics>

<deferred>
## Deferred Ideas

- **Política de denominador** (empresa incompleta entra ou sai da média do profissional) — Fase 120, já registrada como decisão aberta em `REQUIREMENTS-v21.md`.
- **Aposentar `margemPontos()`** — Fase 120, quando a flag existir.
- **Reescrever os invariantes de `DesempenhoShopeeScoreTest`** para o modo flag-ligada — Fase 120 (ver `<risks>`).
- **Persistir a linha por empresa** — Fase 122.
- **Exibir a lista de empresas com nota** — Fase 123.

</deferred>

---

*Phase: 119-score-por-empresa-v21-0*
*Context gathered: 2026-07-28*
