# Phase 123: Telas e relatórios (v21.0) - Context

**Gathered:** 2026-08-03
**Status:** Ready for planning

<domain>
## Phase Boundary

As telas de desempenho passam a **explicar a régua em linguagem simples** e a **mostrar a nota de cada empresa** da carteira, lendo o detalhe já persistido pela Fase 122 — sem quebrar snapshot antigo e sem alterar nenhum cálculo.

**Esta fase não calcula nada.** Nenhuma régua, nenhuma agregação e nenhum componente de nota muda. `computeVarMargem()` continua em `avg()`, `computeVarFaturamento()` continua em `median()`, as réguas ficam intocadas e a flag `metrics.performance_company_first_score` continua `false`. A fase é de leitura e apresentação.

</domain>

<decisions>
## Implementation Decisions

### Onde a lista por empresa aparece

- **D-01:** A lista de empresas com nota individual aparece **somente em competência fechada**, lendo `desempenho_company_score_snapshots` (SELECT puro, custo zero de API). O modo "Em curso" segue exatamente como está hoje — nada de `incluirEmpresasScore=true` em request interativo. Motivo: o dispatcher roda 1× por empresa e este módulo já teve página de 70s por fan-out de API (ver `.planning/learnings/desempenho-bonificacao.md` §5).

- **D-02:** O seletor de mês fechado em `PerformanceController::show()` passa a **derivar as competências dos dados** (as que realmente têm snapshot mensal do profissional), em vez do filtro fixo `whereDate('mes_referencia', '>=', '2026-08-01')`.

  **Por que isso é obrigatório e não é scope creep:** com o filtro atual o dropdown está **vazio em produção** e o botão "Mês fechado" desabilitado — `2026-06-01 < 2026-08-01`. Como `desempenho:consolidar-mes` roda no último dia do mês congelando o **mês anterior**, o primeiro `mes_referencia` a passar naquele corte só existiria em **30/09/2026**. Ou seja: sem esta mudança, a entrega principal da fase (o critério 2 do ROADMAP) **não seria alcançável por nenhum usuário** e o checkpoint visual teria que ser feito sem dado real. Hoje só **2026-06** tem detalhe por empresa (286 linhas, rollout da Fase 122); **2026-07** entra sozinho em 31/08.

- **D-03:** Quando a competência selecionada **não tem** detalhe por empresa (mês ainda não congelado, ou snapshot anterior à Fase 122), a seção **aparece com um aviso curto** explicando o motivo — nunca some silenciosamente. Quem viu a lista em 2026-06 e não a vê em 2026-07 precisa entender que não quebrou.

### Rótulo e unidade da margem

- **D-04:** Enquanto a flag estiver desligada, o **card agregado do topo continua no número legado** (`componentes.var_margem_pct`, variação relativa) — porque é ele que produz a `nota_final` exibida na mesma tela. Pontos percentuais aparecem **apenas na lista por empresa**, que é onde são a unidade real do cálculo. Nenhum número em destaque pode contradizer a nota ao lado dele.

- **D-05:** A margem por empresa é apresentada como **antes → depois + variação** (ex.: `14,1% → 12,0%   −2,1`), usando `margem_pct_anterior` e `margem_pct_atual` do snapshot. O "ponto percentual" fica auto-evidente pela leitura, sem depender do termo — que é o que UIEM-01 pede ("sem jargão não auto-explicativo"). Sai o `percentageMargin` do sublabel atual do card, que é nome de campo de API.

### Empresas que não entram na nota

- **D-06:** A lista é dividida em **duas seções com denominador explícito**: "entraram na conta (N)" e "não entraram (M)", cada linha da segunda com o motivo. Torna impossível ler a nota sem ver sobre quantas empresas ela foi feita. Casos reais que isto expõe pela primeira vez, registrados no learnings: **Felipe** tem margem sobre 3 de 30 empresas.

- **D-07:** Empresa **Shopee entra na conta** (`status='complete'`) com a célula de margem marcada como **valor provisório** — selo curto do tipo "Shopee: sem dado de margem". É limitação da fonte, não desempenho ruim, e sem o selo a leitura natural é a errada.

  **Correção de fato registrada:** durante a discussão foi afirmado que empresa só-Shopee ficaria fora do denominador. **Está errado.** Pela D-02 do `CompanyScoreService`, Shopee entra como `complete` com `margem_pontos = 1.0` fixo (placeholder da Fase 109) e a régua de margem nunca é aplicada sobre ela. Consequência: **Matheus Estrela**, de carteira só-Shopee, tem nota puxada para baixo sistematicamente por placeholder. O planner deve tratar Shopee como "dentro da conta, com ressalva visual", nunca como exclusão.

### Telas admin (UIEM-04)

- **D-08:** No **Relatório de Bonificação**, a tabela continua uma linha por profissional e ganha **linha expansível** com as empresas, a nota de cada uma e os três componentes. Preserva a leitura de "quem bateu o bônus" e põe a justificativa a um clique.

- **D-09:** O **PDF do relatório continua resumo** (uma linha por profissional, como hoje). É o documento que circula para gestão/RH e ganha em ser curto; a auditoria empresa a empresa acontece na tela. Sem segundo template dompdf nesta fase.

- **D-10:** A **Auditoria de Bônus** já lista as empresas de cada profissional — recebe a **coluna de nota por empresa** lendo a mesma fonte do ranking, com a mesma regra de ausência da D-03. Não foi discutida separadamente porque as decisões acima a resolvem por inteiro.

### Retrocompatibilidade (UIEM-03)

- **D-11:** Snapshot antigo **sem `empresas_score`** renderiza no visual anterior (4 cards + faixa), acrescido do aviso da D-03. Payload **sem `var_margem_pp`** exibe `var_margem_pct` com rótulo legado. A ausência de chave nunca pode virar erro de render nem `undefined` na tela.

### Claude's Discretion

- Ordenação dentro de cada seção da lista, densidade da tabela e escolha de componente (tabela vs cards responsivos) — desde que respeite os tokens `ecf-*`, dark theme e `cn()`.
- Texto exato dos selos e do aviso de ausência, em pt-BR e sem jargão.
- Tradução dos slugs de `quality.motivos` para frases legíveis.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Conhecimento durável do módulo (ler PRIMEIRO)
- `.planning/learnings/desempenho-bonificacao.md` — regras que divergem de propósito (mediana no faturamento × média na margem), fragilidade de fronteira da régua, disciplina de conferir por banco e não por stdout, armadilhas de MariaDB, estado dos gates da v21.0. Apontado pelo `CLAUDE.md` como leitura obrigatória.
- `.planning/todos/pending/margem-regua-decisao-2026-08-03.md` — decisão de **não** mexer na régua nem na agregação da margem, com os números que a embasaram. Não refazer a análise.

### Requisitos e fase anterior
- `.planning/REQUIREMENTS-v21.md` — UIEM-01 a UIEM-04 são os requisitos desta fase.
- `.planning/ROADMAP.md` §"Phase 123" — os 5 critérios de sucesso.
- `.planning/phases/122-persist-ncia-por-empresa-e-comandos-v21-0/122-CONTEXT.md` — contexto herdado da fase que persistiu o detalhe por empresa.
- `.planning/phases/122-persist-ncia-por-empresa-e-comandos-v21-0/122-ROLLOUT.md` — evidência do que existe em produção (286 linhas de 2026-06) e o que não existe.
- `.planning/phases/122-persist-ncia-por-empresa-e-comandos-v21-0/122-VERIFICATION.md` — prova de que réguas e agregações ficaram intocadas.

### Código que define os contratos
- `app/Services/Desempenho/CompanyScoreService.php` §D-01 a D-04 (docblock) — shape da linha por empresa e a regra do placeholder Shopee.
- `app/Models/DesempenhoCompanyScoreSnapshot.php` + `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` — colunas e precisões do que está persistido.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Shape por empresa já pronto e rico** — `company_id`, `company_name`, `fonte_financeira`, `nps_pontos`, `faturamento_atual/anterior/var_pct/pontos`, `margem_pct_atual/anterior`, `margem_var_pp`, `margem_pontos`, `componentes_presentes`, `nota_empresa`, `nota_empresa_parcial`, `status`, `quality{revenue_diff_source, margin_diff_source, margin_source, motivos}`. A tela não precisa derivar nada — só formatar.
- **`Performance/Show.jsx`** — já tem `ParametroCard`, `FaixaBonusCard`, o segmento de período (Em curso / Bônus atual / Mês fechado) e os banners de contexto. A seção nova entra abaixo do bloco "Info carteira".
- **`Desempenho/Auditoria.jsx`** — já renderiza a lista de empresas por profissional; é ponto de encaixe direto para a coluna de nota (D-10).
- **`cn()` de `@/lib/utils`**, tokens `ecf-*` e o padrão de selo `rounded-full bg-white/[0.04] border border-white/[0.08]` já usado no cargo em `Show.jsx`.

### Established Patterns
- **Snapshot-first com fallback** — `show()` e `RelatorioBonificacaoController` já fazem "usa `breakdown_json` se existir, senão `computeCached()`". A seção nova segue o mesmo padrão, mas **sem fallback de cálculo**: sem snapshot, é o aviso da D-03.
- **Ausência de chave nunca quebra render** — o payload evoluiu por adição em várias fases; o front já convive com campos que podem não existir.
- **`status` como string, nunca enum** — o serviço pode ganhar valores novos; o front precisa de `default` no mapa de rótulos.

### Integration Points
- `PerformanceController::show()` — carrega as linhas por empresa da competência e monta `meses_disponiveis` pela D-02.
- `RelatorioBonificacaoController::index()` — precisa das linhas por empresa para a expansão (D-08); o método `pdf()` **não** muda (D-09).
- `BonusAuditoriaController::index()` — acrescenta a nota por empresa às empresas já listadas (D-10).

</code_context>

<specifics>
## Specific Ideas

- Formato pedido para a margem por empresa: `14,1% → 12,0%   −2,1` — os dois absolutos visíveis e a diferença ao lado.
- Cabeçalho da seção deve deixar o denominador explícito no próprio título: "entraram na conta (3)" / "não entraram (27)".
- O selo da Shopee deve dizer que **falta o dado**, não que a margem foi ruim.

</specifics>

<deferred>
## Deferred Ideas

- **Ligar a flag `performance_company_first_score`** — depende do gate MPP-04, que continua `reprovado` (faltam 2 rodadas do probe). Fora desta fase: aqui só se exibe o que já é calculado, sem trocar a origem da nota.
- **Calibrar as réguas / reduzir a fragilidade de fronteira** — decisão de política de bônus (diretoria), registrada em `.planning/todos/pending/margem-regua-decisao-2026-08-03.md`. Medição útil que ficou por fazer: quantas pessoas ficam a menos de 0,5 pp de uma fronteira de régua.
- **Reconsolidar 2026-07 antes de 31/08** para antecipar dado na tela — deliberadamente NÃO feito: recompute de competência fechada pode mexer em pagamento de quem está perto da fronteira (learnings §2).
- **Débora Lima cai em "sem carteira" na consolidação** — `.planning/todos/pending/280726-debora-sem-carteira-na-consolidacao-mensal.md`. Ela simplesmente não terá linha nas telas desta fase; é sintoma de outro problema, não desta fase.

</deferred>

---

*Phase: 123-Telas e relatórios (v21.0)*
*Context gathered: 2026-08-03*
