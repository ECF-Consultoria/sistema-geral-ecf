# Requirements: ECF Admin — Milestone v21.0

**Defined:** 2026-07-27
**Milestone:** v21.0 — Desempenho por nota individual de empresa
**Core Value:** Trocar a granularidade do motor de bonificação: sair de componentes agregados por profissional e passar a calcular a nota de cada empresa primeiro (`nota_empresa = (NPS + faturamento + margem_pp) / 3`), com a nota do profissional virando a média das notas das empresas da carteira. A régua deixa de ser aplicada depois da média — passa a ser aplicada empresa por empresa.
**Plano canônico:** `plano-implementacao-desempenho-por-empresa.md` (raiz do projeto).

## Decisões travadas (LOCKED — não reperguntar)

### D1 — Margem passa a usar pontos percentuais (pp)
Decisão do usuário (2026-07-27). `margem_var_pp = percentageMargin.value − percentageMargin.prev`, do payload da Adman. **Isto reabre deliberadamente o hotfix `a413e823` de 2026-07-24** (`AdmanMetricDiffService::resolveMargemPct()`: *"usa SEMPRE o `.diff` nativo, NUNCA cálculo local"*) — a reabertura é necessária porque pp **não é expressável** pelo `.diff` nativo, que é variação relativa. `diff_pct` continua existindo, intocado, para os consumidores legados.

**Mitigação obrigatória:** `percentageMargin.prev` **nunca foi validado quanto a estabilidade**, e existe histórico de instabilidade nessa mesma métrica (ver memória `project_adman_margem_diff_instavel_bonus`). A Fase 117 tem um probe de estabilidade como critério de sucesso — se `prev` oscilar, a milestone para antes de amarrar pagamento de bônus nele.

### D2 — A régua de margem atual é REUSADA lida como pp, sem recalibrar
Decisão do usuário (2026-07-27). Cortes mantidos: `≤ −5 → 1`, `≤ −2 → 2`, `≤ 1 → 3`, `≤ 4 → 4`, `> 4 → 5`, agora lidos como pontos percentuais.

**Usuário ciente do efeito:** pp é muito menos volátil que variação relativa, então a distribuição de notas de margem **comprime na faixa 3–4**. Leitura de referência da carteira do Luiz: `~−0,59 pp → nota 3`, contra régua 5 no snapshot congelado atual e régua 1 no cálculo local determinístico. A Fase 121 mede a distribuição real de pp na carteira inteira antes de ligar a flag.

### D3 — Granularidade: empresa primeiro, profissional depois
`nota_empresa = round((nps_pontos + faturamento_pontos + margem_pontos) / 3, 2)`
`nota_final = round(média(nota_empresa das empresas consideradas), 2)`

### D4 — Não alterar a baseline
`previous_equal_length_window` permanece (decisão do usuário 2026-07-17, travada na Fase 109). Fora de escopo desta milestone.

### D5 — Placeholder de margem Shopee preservado
Empresa Shopee mantém `margem_pontos = 1.0` (trava da Fase 109). Quando a Shopee fornecer margem, vira `margem_var_pp` real sem mudar a fórmula.

## DECISÃO EM ABERTO (resolver no discuss-phase da Fase 120)

**Tratamento de empresa sem baseline.** O plano (§3.4) propõe marcar o profissional como `partial` quando qualquer empresa esperada estiver sem baseline. Isso **contradiz** `DESEMP-06` e a trava da Fase 109 (*"profissional só-Shopee NÃO deve cair em `blocked`/`partial` por ausência de margem"*), e o histórico local torna o caso comum, não excepcional (`adman_metrics` começa ~21/05; Shopee sem baseline antes de 01/06 — ver memória `project_created_at_reimport_e_historico_metricas`).

**Recomendação:** excluir a empresa do denominador em vez de propagar `partial` para o profissional inteiro. Decidir antes de planejar a Fase 120.

## v21.0 Requirements

Cada requirement mapeia para exatamente uma phase no ROADMAP.md.

### MPP — Margem em pontos percentuais (Fase 117)

- [x] **MPP-01**: `AdmanMetricDiffService` expõe `prev_value` e `diff_pp` no shape de cada métrica, preservando `value`, `diff_pct` e `diff_source` inalterados para consumidores existentes
- [x] **MPP-02**: `contribution_margin_pct.diff_pp = value − prev_value`, calculado **apenas** quando `comparison_mode === 'previous_equal_length_window'` e ambos são numéricos; `null` em qualquer outro caso
- [x] **MPP-03**: Cache key `adman:diff:v5` → `v6` (o shape mudou); resultado velho não é servido para o shape novo
- [ ] **MPP-04**: Existe um probe de estabilidade de `percentageMargin.prev` — N leituras da mesma empresa em competência fechada, com relatório de variância — e o resultado é apresentado ao usuário antes de qualquer fase amarrar pagamento em `diff_pp`
- [x] **MPP-05**: `ShopeeMetricDiffService` retorna `diff_pp = null` (Shopee não tem margem), sem quebrar o placeholder 1.0 da Fase 109
- [x] **MPP-06**: Fixture conhecida comprova o cálculo: `value=27,47` e `prev=24,08` produzem `diff_pp=3,39`, e `diff_pct` continua `14,09`

### NPSE — NPS por empresa (Fase 118)

- [ ] **NPSE-01**: `NpsPorEmpresaService::notasNpsPorEmpresa($user, $mesNps, $invalidadas)` retorna nota de NPS agrupada por `company_id`, com contagem e origem por ramo
- [ ] **NPSE-02**: Os três ramos atuais são preservados sem mudança semântica — `nps_score_assignments`, caminho legado (`nps_surveys`/`nps_responses`, `->principal()`) e `nps_imputed_assignments` — mantendo a dedupe por `(response_id, role)` e por `(survey_id, role)`
- [ ] **NPSE-03**: A janela M+1 é preservada: competência financeira M lê NPS coletado em M+1; mês em curso usa piso `1.0`; M+1 encerrado sem resposta usa `0.0` que vira `1.0` pelo clamp
- [ ] **NPSE-04**: Empresa invalidada na competência (`bonus_invalidacoes`) não entra no NPS por empresa (coerente com D5 da Fase 116)
- [ ] **NPSE-05**: Empresa com Performance **e** Shopee não duplica NPS por serviço
- [ ] **NPSE-06**: O teste de coerência entre call-sites da Fase 116 (116-08) conhece este novo call-site e continua verde

### EMPS — Score por empresa (Fase 119)

- [ ] **EMPS-01**: `CompanyScoreService` produz uma linha por empresa com o contrato do plano §3.1 (`company_id`, `fonte_financeira`, `status`, componentes brutos, pontos, `nota_empresa`, `quality`)
- [ ] **EMPS-02**: A régua de faturamento é aplicada **por empresa**, antes de qualquer média
- [ ] **EMPS-03**: A régua de margem é aplicada sobre `margem_var_pp`, reusando os cortes atuais (D2), **nunca** sobre `diff_pct`
- [ ] **EMPS-04**: `nota_empresa = round((nps_pontos + faturamento_pontos + margem_pontos) / 3, 2)`
- [ ] **EMPS-05**: `MetricDiffDispatcher::compute()` é chamado **uma única vez por empresa** — hoje `computeVarFaturamento()` e `computeVarMargem()` chamam duas vezes indiretamente
- [ ] **EMPS-06**: Resolução de fonte financeira vencedora por empresa preservada (Adman vence Shopee); empresa Shopee usa `margem_pontos = 1.0` marcado como `quality.margin_source = placeholder_shopee`
- [ ] **EMPS-07**: Cada linha expõe `status` e `quality` (fontes de diff e motivos), permitindo auditar por que uma empresa ficou incompleta

### AGRE — Agregação do profissional (Fase 120)

- [ ] **AGRE-01**: Com a flag ligada, `nota_final` do profissional é exatamente a média das `nota_empresa` das empresas consideradas
- [ ] **AGRE-02**: Feature flag `config('metrics.performance_company_first_score')` controla a troca; `empresas_score` é calculado em **shadow** nos dois modos, para auditoria
- [ ] **AGRE-03**: `DesempenhoScoreService::cacheKey()` sobe de `v12` para `v13` (v12 já foi consumido pela Fase 116-02), e as 4 suítes com a string hardcoded são atualizadas junto — `DesempenhoShopeeScoreTest`, `Phase116/NpsFloorDesempenhoTest`, `Phase96/NpsInvalidacaoRespostaTest`, `V18/DesempenhoMetadadosCacheTest`
- [ ] **AGRE-04**: O payload expõe `empresas_score` e `componentes.var_margem_pp`, preservando as chaves legadas (`empresas_carteira`, `empresas_com_baseline`, `margem_amostra`, `componentes_disponiveis`, `score_status`, `faixa_bonus`, `faixa_promovida`, `componentes.var_margem_pct`)
- [ ] **AGRE-05**: O tratamento de empresa sem baseline segue a decisão tomada no discuss-phase desta fase, sem contradizer `DESEMP-06` nem a trava da Fase 109
- [ ] **AGRE-06**: `score_status` (`official` / `partial` / `blocked`) permanece coerente: profissional só-Shopee continua produzindo `nota_final`, não cai em `blocked` por ausência de margem

### ROLL — Comparação e validação antes do rollout (Fase 121)

- [ ] **ROLL-01**: `php artisan desempenho:comparar-score-empresa --mes=YYYY-MM` produz, por profissional, `nota_antiga`, `nota_nova`, `delta`, contagem de empresas total/complete/partial e a maior causa do delta
- [ ] **ROLL-02**: A comparação roda sobre a última competência fechada e as amostras de risco são conferidas manualmente — profissional com poucas empresas, com muitas empresas, empresa com queda grande de faturamento, empresa com pp positivo, empresa sem baseline, empresa invalidada, profissional com Shopee
- [ ] **ROLL-03**: A **distribuição real de `margem_var_pp` na carteira inteira** é medida e apresentada, confirmando (ou refutando) que a régua reusada de D2 produz dispersão de notas aceitável antes de ligar a flag

### SNAP — Persistência e comandos (Fase 122)

- [ ] **SNAP-01**: `empresas_score` é persistido em `desempenho_score_snapshots.breakdown_json`
- [ ] **SNAP-02**: Tabela `desempenho_company_score_snapshots` explica o resumo empresa por empresa, com `unique(user_id, company_id, mes_referencia)` e índices de leitura
- [ ] **SNAP-03**: `ConsolidarMesDesempenho`, `SnapshotDesempenhoScores` e `WarmDesempenhoCache` gravam as linhas por empresa no fechamento
- [ ] **SNAP-04**: Invalidar empresa por competência remove também as linhas de `desempenho_company_score_snapshots` daquela competência
- [ ] **SNAP-05**: `margem_amostra` passa a contar cobertura de `margem_var_pp`, não de `var_margem_pct`
- [ ] **SNAP-06**: O rollout inclui `desempenho:consolidar-mes --mes=` para competências fechadas — sem isso, ranking, dashboard e Relatório de Bonificação continuam mostrando a nota antiga; e o gate `FIXMARG-03` (recusa persistir com cobertura de margem < 0,7) é conferido por reconsulta ao snapshot, nunca por stdout

### UIEM — Telas e relatórios (Fase 123)

- [ ] **UIEM-01**: A dimensão de margem é rotulada e explicada em linguagem simples ("quantos pontos percentuais a margem subiu ou caiu"), sem jargão não auto-explicativo
- [ ] **UIEM-02**: O detalhe do profissional lista as empresas da carteira com a nota de cada uma e seus três componentes
- [ ] **UIEM-03**: Snapshots antigos sem `empresas_score` continuam renderizando no visual anterior; sem `var_margem_pp`, exibe `var_margem_pct` com rótulo legado
- [ ] **UIEM-04**: Relatório de Bonificação e Auditoria de Bônus exibem `nota_empresa` e os componentes por empresa, lendo a mesma fonte de snapshot/payload que o ranking

## Critérios de aceite globais (do plano canônico §7)

- Para cada profissional, o payload contém `empresas_score`
- `nota_final` é exatamente a média das `nota_empresa` das empresas consideradas
- Faturamento aplica a régua **por empresa** antes da média
- Margem aplica a régua sobre **pontos percentuais**, nunca sobre `percentageMargin.diff`
- Exemplo `15,0% → 18,2%` gera `margem_var_pp = 3,2` e nota de margem `4`
- NPS continua respeitando M+1; imputados e invalidações continuam funcionando
- Empresa invalidada sai do financeiro **e** do NPS da competência
- Relatório de Bonificação e ranking usam a mesma fonte
- Snapshots antigos ainda renderizam
- Cache de desempenho e cache Adman versionados

## Out of Scope (v21.0)

- **Recalibrar a régua de margem para pp** — decisão D2 reusa a régua atual conscientemente. Se a distribuição medida na Fase 121 mostrar compressão inaceitável, vira pauta de diretoria numa milestone futura, não aqui.
- **Mudar a baseline `previous_equal_length_window`** — travado na Fase 109 e espalhado em testes e telas.
- **Margem real de Shopee** — não existe fonte; placeholder 1.0 preservado (Fase 109).
- **Absenteísmo** — segue placeholder, fora do escopo desta troca de granularidade.
- **Freeze de junho/2026 (prazo 31/07 14h BRT)** — esta milestone é a opção (A) da pendência `.planning/todos/pending/metrica-margem-bonus-fragil.md`, mas **não fica pronta a tempo**. O que fazer com o congelamento de junho é decisão separada e imediata.
- **Valor em R$ do bônus por faixa/cargo** — segue no escopo do Relatório de Bonificação (memória `project_relatorio_bonificacao_mvp`).

## Dependência externa

**A Fase 118 (NPS por empresa) não pode executar antes da Fase 116 fechar.** Faltam `116-06` (backfill retroativo com gate humano), `116-07` (UI da área NPS) e `116-08` (teste de coerência entre call-sites). A Fase 118 adiciona um quarto call-site da regra de piso de NPS; executá-la antes faria o backfill retroativo rodar contra um payload cuja forma mudou no meio.

## Traceability

| REQ-ID | Phase | Status |
|--------|-------|--------|
| MPP-01 | Fase 117 | Complete |
| MPP-02 | Fase 117 | Complete |
| MPP-03 | Fase 117 | Complete |
| MPP-04 | Fase 117 | Pending |
| MPP-05 | Fase 117 | Complete |
| MPP-06 | Fase 117 | Complete |
| NPSE-01 | Fase 118 | Pending |
| NPSE-02 | Fase 118 | Pending |
| NPSE-03 | Fase 118 | Pending |
| NPSE-04 | Fase 118 | Pending |
| NPSE-05 | Fase 118 | Pending |
| NPSE-06 | Fase 118 | Pending |
| EMPS-01 | Fase 119 | Pending |
| EMPS-02 | Fase 119 | Pending |
| EMPS-03 | Fase 119 | Pending |
| EMPS-04 | Fase 119 | Pending |
| EMPS-05 | Fase 119 | Pending |
| EMPS-06 | Fase 119 | Pending |
| EMPS-07 | Fase 119 | Pending |
| AGRE-01 | Fase 120 | Pending |
| AGRE-02 | Fase 120 | Pending |
| AGRE-03 | Fase 120 | Pending |
| AGRE-04 | Fase 120 | Pending |
| AGRE-05 | Fase 120 | Pending |
| AGRE-06 | Fase 120 | Pending |
| ROLL-01 | Fase 121 | Pending |
| ROLL-02 | Fase 121 | Pending |
| ROLL-03 | Fase 121 | Pending |
| SNAP-01 | Fase 122 | Pending |
| SNAP-02 | Fase 122 | Pending |
| SNAP-03 | Fase 122 | Pending |
| SNAP-04 | Fase 122 | Pending |
| SNAP-05 | Fase 122 | Pending |
| SNAP-06 | Fase 122 | Pending |
| UIEM-01 | Fase 123 | Pending |
| UIEM-02 | Fase 123 | Pending |
| UIEM-03 | Fase 123 | Pending |
| UIEM-04 | Fase 123 | Pending |
