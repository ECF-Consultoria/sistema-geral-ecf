# Phase 136: Métricas manuais por empresa/mês no Desempenho — Context

**Gathered:** 2026-08-11
**Status:** Ready for planning

<domain>
## Phase Boundary

Duas entregas acopladas:

1. **Lançamento manual de métricas financeiras por empresa × mês**, em tela própria admin-only, alimentando o motor de Desempenho. O faturamento se lança pelo valor; a margem se lança pelo **CMV do mês**, e o sistema deriva `margem % = (fat − CMV) / fat` e os pontos percentuais contra o mês anterior. Vale só para competência **não consolidada** — a trava de congelamento continua valendo.

2. **Correção do desempate de fonte financeira.** Hoje `$sources->contains('adman') ? 'adman' : $sources->first()` faz `'adman'` vencer **sem verificar se a empresa tem conta Adman**, e a mesma empresa no mesmo mês aparece com número para um profissional e em branco para outro.

**Por que a fase existe (medido em 2026-08-11, `/performance/21?mes=2026-07`, 11 de 30 empresas sem faturamento):** 10 empresas com vínculo Shopee sem conexão OAuth, 1 conta nova sem mês-base, 1 vítima do desempate, 3 cadastros de teste. Combinado com o divisor fixo de 3 (ausente = zero), carteira só-Shopee tem **teto de 3,33** e nunca alcança os 4,00 da primeira faixa de bônus, porque a Shopee não expõe CMV.

**Fora de escopo:** recalibrar réguas; reconsolidar competências fechadas; mudar a agregação (mediana no faturamento, média na margem); mexer no piso de NPS; corrigir o lock global por mês do `WarmDesempenhoDispatcher`.

</domain>

<decisions>
## Implementation Decisions

### Precedência e ciclo de vida do valor manual

- **D-01 · Fonte explícita por célula.** Cada `(empresa, mês)` guarda a fonte escolhida: `auto` (default) ou `manual`. Só célula marcada `manual` ignora a API. Isso separa "não lancei" de "lancei e mandei usar" — a intenção fica auditável, e é literalmente o que o goal do ROADMAP descreve.
- **D-02 · `manual` nunca reverte sozinho.** Quando a API passa a devolver dado para uma célula marcada `manual`, o valor manual continua mandando. A grade passa a exibir o valor da API ao lado e sinaliza divergência; voltar para `auto` é ato explícito do admin. Motivo concreto: OAuth conectado em 28/07 (Tuki Pet) faz a API ter dado de 4 dias, não do mês — reverter sozinho trocaria número bom por número parcial e mexeria em nota sem ninguém pedir.
- **D-03 · Consolidar trava e deixa rastro.** Ao rodar `desempenho:consolidar-mes`, a célula fica **read-only** (a trava de congelamento continua valendo, sem exceção) **e** `desempenho_company_score_snapshots` ganha marcação de que aquele número veio de lançamento manual. Quem auditar junho em dezembro tem que conseguir distinguir número medido de número digitado — esse número decidiu bônus.
- **D-04 · Selo discreto no lado do profissional.** Em `/performance/{user}`, a linha da empresa cujo número veio de lançamento manual recebe marcador (ícone + tooltip "valor lançado manualmente"), **sem** nome de quem lançou. Esconder a origem de um número que decide bônus é o tipo de coisa que destrói confiança quando descoberta; expor nominalmente quem digitou vira atrito interno.

### Janela de comparação e baseline

- **D-05 · Célula manual compara mês cheio × mês cheio.** A célula manual carrega a própria janela — mês calendário — e um `diff_source` próprio (ex. `manual_mes_calendario`), sinalizado na tela. **Custo assumido e declarado:** dentro da mesma carteira, a loja manual é comparada por mês cheio enquanto as demais usam o recorte de dias do resolver (`same_interval_previous_month`: em 11/08, 01–11/08 contra 01–11/07). É inconsistência de janela **declarada**, não escondida.
  - Consequência prática: um valor de mês cheio só existe quando o mês acabou. O lançamento acontece de fato na janela entre o fim do mês e a consolidação — que é exatamente quando o CMV chega em lote. **O "em curso" do goal faz menos trabalho do que aparenta; ver D-09.**
- **D-06 · Lado base em cascata.** A base da comparação resolve nesta ordem: (1) lançamento manual do mês anterior, se existir; (2) soma do mês calendário anterior **inteiro** pela API (o mês anterior está fechado — o valor cheio existe); (3) `null`, exatamente como hoje. Menos digitação, os dois lados sempre em mês cheio, e a base continua sendo número medido quando existe.
- **D-07 · Dois eixos independentes por célula.** Faturamento e margem alternam `auto`/`manual` **separadamente**. Loja Shopee: só o CMV vira manual, o faturamento continua vindo da API (10 dos 11 casos de 2026-07 são assim). Empresa sem OAuth: faturamento manual, e CMV se houver. Um toggle único obrigaria redigitar um faturamento que a API já entrega correto — risco de errar o lado que estava certo.
- **D-08 · Origem do faturamento na derivação da margem.** A margem sai do **faturamento efetivo da célula em mês cheio**: manual se aquele eixo estiver marcado `manual`, senão o mês calendário inteiro pela API. Sem faturamento em nenhuma das duas fontes, o CMV sozinho **não** produz margem e a grade diz isso na célula. Nada é derivado de janela diferente da do próprio valor.

### Exceção explícita a uma regra travada anteriormente

- **D-EXC-01 · O caminho manual é exceção estreita e declarada ao hotfix de 2026-07-24.** Aquele hotfix travou que "a variação de margem vem sempre do valor nativo da Adman, nunca de cálculo local". Margem derivada de CMV lançado à mão é, por definição, cálculo local. A exceção vale **somente** para célula marcada `manual`, é identificada pelo `diff_source` próprio (D-05) e **não** relaxa a regra para o caminho automático. Registrado aqui porque a próxima sessão vai ler o hotfix nos learnings e tentar desfazer isto.

### Claude's Discretion

O usuário optou por não discutir estas três áreas e delegou a decisão. Estão explicitadas para contestação — se qualquer uma estiver errada, corrigir aqui é barato; corrigir depois do plano, não.

- **D-09 · Fronteira de "não consolidada" = ausência de linha `origem='consolidar_mes'`.** A competência conta como consolidada quando existe pelo menos uma linha em `desempenho_company_score_snapshots` com `origem='consolidar_mes'` para aquele `mes_referencia`. É o **mesmo sinal** que a trava do `CompanyScoreSnapshotWriter` (D-122-02) já usa — nenhum conceito novo é inventado.
  - **Leitura deliberada do goal:** "em curso **e** não consolidada" é aplicado como **"não consolidada"**, não como "mês corrente". Julho/2026 está fechado pelo calendário e **não** consolidado (esperando NPS coletado em agosto) — é precisamente o caso que a fase precisa atender, e D-05 torna o valor de mês cheio impossível antes do fim do mês. Ler literalmente "em curso" entregaria uma tela inútil.
  - Mês nunca consolidado, por antigo que seja, permanece editável: se nunca foi consolidado, nada foi pago sobre ele. A grade abre no mês corrente + anterior; os demais ficam alcançáveis, não bloqueados.
- **D-10 · Corrigir os TRÊS call-sites, com resolvedor único.** O desempate está duplicado em [`CompanyScoreService.php:174`](../../../app/Services/Desempenho/CompanyScoreService.php) (caminho vivo da nota), [`DesempenhoScoreService.php:915`](../../../app/Services/DesempenhoScoreService.php) (`computeUniverso()` — alimenta `nota_final_legado`, `var_margem_pct` e `margem_amostra.legado`) e [`PortfolioController.php:125`](../../../app/Http/Controllers/PortfolioController.php) (página Carteira, de onde sai o rótulo de marketplace). Corrigir só o vivo faria a **mesma empresa** resolver fonte diferente em dois lugares do mesmo payload, e a Carteira exibir marketplace divergente do Desempenho. Extrair um resolvedor único em vez de triplicar a correção — a duplicação byte-a-byte das réguas (C-03 da Fase 119) já custou caro.
  - Regra corrigida: `'adman'` só vence se a empresa tiver conta Adman de fato. O critério de "tem conta Adman" precisa ser decidido pela pesquisa entre `companies.adman_account_id` não nulo e/ou existência de linha em `adman_metrics` na janela — **é pergunta para o RESEARCH**, não para o planner adivinhar.
- **D-11 · Retroatividade: só daqui para frente, com relatório de impacto read-only.** A fase **não** reconsolida competência fechada. Learnings §2: qualquer recompute mexe em pagamento de quem está perto da fronteira de 4,00, e junho já foi reconsolidado uma vez (5 contemplados → 1). A fase entrega um comando/relatório **read-only** listando quais empresas e profissionais mudariam de número, para o usuário decidir a reconsolidação como ato separado e deliberado, com backup.
- **D-12 · Trilha de auditoria no banco, não na tela.** A tabela de lançamentos carrega autor e timestamp (e o valor anterior em caso de edição), e o model usa `spatie/laravel-activitylog` como o resto do projeto. A tela não expõe o nome (D-04) — o rastro existe para auditoria, não para exibição.

### Consequências obrigatórias já medidas (o planner NÃO pode omitir)

- **Bump de cache `desempenho.compute.v19` → `v20`** ([`DesempenhoScoreService.php:449`](../../../app/Services/DesempenhoScoreService.php)). Sem bump, o dashboard serve nota velha. O bump quebra a string hardcoded em **6** arquivos: `tests/Feature/DesempenhoShopeeScoreTest.php`, `tests/Feature/Phase116/NpsFloorDesempenhoTest.php`, `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php`, `tests/Feature/V16/BonusDualPathRegressaoTest.php`, `tests/Feature/V16/DesempenhoElegibilidadeTest.php`, `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` — atualizar todas no mesmo commit.
- **Gate de hash da Fase 119.** `assertHashDesempenhoScoreServiceIntocado()` compara o SHA-256 de `DesempenhoScoreService.php` contra constante congelada, repetido em **5** arquivos: `tests/Feature/Phase119/CompanyScoreService{Dispatcher,Fonte,Margem,Reconciliacao,Status}Test.php`. Tocar a linha 915 (D-10) **quebra os 5** na primeira asserção e mascara tudo depois. Rotacionar a constante nos 5 no mesmo commit.
- **Baseline de falhas pré-existentes.** ~9–10 testes falham **sem** as mudanças desta fase (learnings §0.02: `V18\CarteiraPeriodoDiffTest`, `V18\DesempenhoPeriodoOficialTest`, `DesempenhoShopeeScoreTest`, `V18\ConsolidarMesJanelaNpsTest`, `V18\JanelaNpsBonusTest`). Medir a baseline com as mudanças em `git stash` antes de investigar "regressão".
- **Gate FIXMARG-03.** D-10 move `margem_amostra.legado.n_elegivel`, que alimenta o gate de cobertura que **recusa** congelar abaixo de 0,7. Medir o efeito antes de consolidar; conferir por `desempenho:verificar-consolidacao --mes=YYYY-MM --json` (o veredito é o **exit code**), nunca por stdout.
- **MariaDB × SQLite** (learnings §6, para a migration da tabela de lançamentos): nome de índice acima de 64 caracteres falha com 1059 e deixa a migration `Pending` com a tabela criada; `nullOnDelete` exige coluna `nullable()`; enum precisa de branch SQLite.
- **Nunca** `php artisan cache:clear` no VPS (derrubou o site em 30/07). Depois de bump de chave, o clear é desnecessário.
- Árvore compartilhada: sempre `git commit -- <caminhos>`, **nunca** `git add -A` / `git add .`.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Conhecimento durável — leitura obrigatória
- `.planning/learnings/desempenho-bonificacao.md` — íntegro. Em especial **§0.00** (divisor fixo 3 + margem no mês corrente), **§0.04** (diagnóstico das 11 empresas e o furo do desempate — "Endereçado na Fase 136"), **§0.041** (fila `default` parada), **§2** (fragilidade de fronteira: recompute mexe em pagamento), **§3** (mês fechado lê snapshot congelado), **§4** (conferência por reconsulta, nunca stdout), **§5** (cache e proibição do `cache:clear`), **§6** (armadilhas MariaDB), **§0.01/§0.02** (gate de hash e falhas que não são regressão).
- `.planning/todos/pending/margem-regua-decisao-2026-08-03.md` — status `decided-no-action`: régua não muda, margem continua em média. Não reabrir.
- `.planning/todos/pending/metrica-margem-bonus-fragil.md` — histórico da escolha de pontos percentuais (opção A) e por que a métrica é frágil.

### Motor de desempenho — código que a fase toca
- `app/Services/Desempenho/CompanyScoreService.php` — linha de fato por empresa; desempate na linha 174; regras travadas D-01..D-05/C-01..C-04 no docblock.
- `app/Services/DesempenhoScoreService.php` — nota oficial via `computeNotaFinalPorIndicador()`; `computeUniverso()` (desempate na 915); `cacheKey()` na 449.
- `app/Services/Metrics/MetricDiffDispatcher.php` — roteador de fonte financeira (1,5 KB, `match` com whitelist). Ponto natural de injeção do caminho manual.
- `app/Services/Metrics/ShopeeMetricDiffService.php` — margem **sempre** `null` (Shopee não fornece CMV); `revenue` soma a janela direto, dia sem linha é venda zero real.
- `app/Services/Metrics/AdmanMetricDiffService.php` — HTTP-first com fallback guardado por dias-comuns.
- `app/Services/Metrics/MetricPeriodResolver.php` — resolve `current_*`/`baseline_*` e `comparison_mode`. Mês corrente = `same_interval_previous_month`.
- `app/Services/Portfolio/CarteiraContextService.php` — `flagsFinanceirasPorSetor()`: quem responde "qual marketplace" (learnings §0.03 — **não** usar `companies.marketplace`).
- `app/Services/Desempenho/CompanyScoreSnapshotWriter.php` — trava de congelamento por `origem` (D-122-02) e as 3 constantes de origem.
- `app/Services/Desempenho/CompanyScoreSnapshotReader.php` · `app/Models/DesempenhoCompanyScoreSnapshot.php` — snapshot por empresa (alvo do rastro de D-03).
- `app/Http/Controllers/PortfolioController.php` — terceiro call-site do desempate (linha 125).
- `app/Http/Controllers/PerformanceController.php` · `resources/js/Pages/Performance/` — onde entra o selo de D-04.
- `app/Console/Commands/ConsolidarMesDesempenho.php` · `VerificarConsolidacaoDesempenho.php` — congelamento e verificação read-only.

### Convenções do projeto
- `CLAUDE.md` — pt-BR nos comentários, tokens `ecf-*`, `DevCard`, `cn()`, `npm run build` ao fim de qualquer alteração de front, proibição de deploy sem autorização.
- `.planning/codebase/ARCHITECTURE.md` · `STACK.md` · `CONVENTIONS.md` — mapas existentes.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`MetricDiffDispatcher`** — 1,5 KB, um `match` de duas fontes com whitelist explícita e `InvalidArgumentException` no default. Estender aqui (terceira fonte, ou camada de override sobre o resultado) é o caminho mais curto e mantém o shape que Carteira e Desempenho já consomem.
- **Shape de retorno já padronizado** — `{company_id, period, metrics:{revenue, contribution_margin_value, contribution_margin_pct}, quality}`, com `value`/`prev_value`/`diff_pct`/`diff_pp`/`diff_source` por métrica. O caminho manual deve preencher o **mesmo** shape, sem inventar campos, apenas com `diff_source` próprio.
- **`ShopeeMetricDiffService::margemPctNula()`** — o docblock já declara a arquitetura *future-ready*: "quando a Shopee passar a fornecer margem, basta trocar por um cálculo real, sem mudar o shape". O CMV manual é exatamente esse caso.
- **`spatie/laravel-activitylog`** — já em uso em todos os models principais; a trilha de D-12 não precisa de infraestrutura nova.
- **Trava por `origem`** em `CompanyScoreSnapshotWriter` — o padrão de congelamento já existe e é o sinal de D-09; não criar flag paralela.

### Established Patterns
- **`fonte_financeira` é resolvida por VÍNCULO, não pela existência de dado** (learnings §0.04). O dispatcher lê a fonte do vínculo daquele profissional; ver linha em `adman_metrics` não significa que o Desempenho vá lê-la. Sete das dez empresas Shopee sem OAuth **têm** dado na Adman — essa é a armadilha que faz perder tempo.
- **A nota lê variação, nunca valor absoluto** — `faturamento_var_pct` e `margem_var_pp`. Qualquer desenho que só capture o valor do mês corrente não produz nota.
- **`company_users` tem várias linhas por (empresa, papel)**, uma por serviço (Fase 76). Filtrar só por `role` conta a mesma empresa em duas carteiras. Usar `consultorDoServico`/`estrategistaDoServico` e `distinct`.
- **Admin-only via middleware `role:admin`**; rotas de desempenho já usam `permission:core.performance`. A tela nova é admin-only (goal).
- **Réguas duplicadas byte-a-byte** entre `CompanyScoreService` e `DesempenhoScoreService` (C-03) — precedente ruim que D-10 não deve repetir.

### Integration Points
- **Nova tabela** de lançamentos manuais, chaveada por `(company_id, mes_referencia, metrica)` com fonte, valor, autor e timestamp — granularidade exigida por D-07.
- **Rota + página novas** para a grade admin, sob `/desempenho/...` (vizinhas de `desempenho/auditoria-bonus` e `desempenho/configuracao`, ambas já `role:admin`), com página em `resources/js/Pages/Desempenho/`.
- **`MetricDiffDispatcher::compute()`** — override do resultado antes de devolver ao `CompanyScoreService`.
- **`CompanyScoreSnapshotWriter::sync()`** — gravar o rastro de D-03 junto com `fonte_financeira`.
- **`resources/js/Pages/Performance/Show.jsx`** — selo de D-04 na tabela por empresa.

</code_context>

<specifics>
## Specific Ideas

- **Casos-âncora para teste e verificação**, todos medidos em produção em 2026-08-11 (`/performance/21?mes=2026-07`):
  - **Interior Magazine** — sem `adman_account_id`, zero linhas em `adman_metrics`, token Shopee e 71 linhas de métrica. Para Felipe (vínculo shopee) aparece **+37,77%**; para Douglas e Gabriela (vínculo performance) resolve `adman`, lê a conta que não existe e aparece **em branco**. Corrigido, entra com 5 pontos de faturamento nas duas carteiras.
  - **Tuki Pet** — conectou OAuth em 28/07; `MIN(reference_date)` cai dentro do mês atual. Caso-teste de D-02 (manual não reverte quando a API passa a ter dado parcial).
  - **10 empresas com vínculo Shopee sem OAuth** — `shopee_tokens` (app `erp`) vazio e `shopee_metrics` com 0 linhas. Caso-teste dos dois eixos manuais.
  - **Matheus Estrela** — carteira só-Shopee, nenhuma empresa com dado de margem. Caso-teste do teto de 3,33 → efeito real do CMV manual na nota.
- **Decisões já tomadas na sessão de plan-phase de 2026-08-11**, para não reperguntar: **UI-SPEC dispensado** (`--skip-ui`) porque o design system é fechado e documentado no `CLAUDE.md` — uma grade admin de lançamento em lote não abre decisão visual nova; **research habilitado** (a pesquisa precisa responder o critério de "tem conta Adman" de D-10).
- Ao produzir qualquer documento de checkpoint/verificação com dado real: **contadores de carteira podem ser versionados; nome pareado com faixa de bônus, nota final ou valor de bonificação, não** (learnings §11).

</specifics>

<deferred>
## Deferred Ideas

- **Reconsolidação das competências fechadas** após a correção do desempate — decisão separada e deliberada do usuário, com backup prévio (`storage/app/private/backups/desempenho/`). Esta fase só entrega o relatório read-only de impacto (D-11).
- **Lock global por mês do `WarmDesempenhoDispatcher`** — defeito de desenho conhecido e sem fase (learnings §0.041): o teto de poll do front é 2 min (`Show.jsx`, 20 × 6s) mas o lock do warm é 3 min e global por MÊS, não por usuário; quem não pegou o lock queima o poll esperando um job que nunca foi agendado. Fora do escopo desta fase.
- **Exigência de cobertura mínima de dimensões para valer bônus** — o ponto aberto de learnings §0.3 (nota vinda de uma dimensão só). É decisão de negócio, não foi tomada, e o CMV manual reduz o problema sem resolvê-lo.
- **Unificar as réguas duplicadas** entre `CompanyScoreService` e `DesempenhoScoreService` (C-03 da Fase 119) — D-10 unifica só o resolvedor de fonte; as réguas ficam.
- **Calibrar a régua / reduzir fragilidade de fronteira** — pauta de diretoria, decidido explicitamente em 2026-08-03. Medição útil que ficou por fazer: quantas pessoas, em quantas competências, ficam a menos de 0,5 pp de uma fronteira.

### Reviewed Todos (not folded)
- `metrica-margem-bonus-fragil.md` — considerado e **não** incorporado. A decisão de métrica (pontos percentuais) já foi tomada e implementada na milestone v21.0; o que resta é calibragem de régua, que é pauta de diretoria.
- `margem-regua-decisao-2026-08-03.md` — status `decided-no-action`. Entra como referência de leitura obrigatória, não como escopo.
- `baseline-quase-zero-infla-nota-legada.md` — trata da inflação do método legado por baseline quase-zero; o método legado é metadado de auditoria e não decide bônus. Fora do escopo.

</deferred>

---

*Phase: 136-metricas-manuais-por-empresa-mes-no-desempenho*
*Context gathered: 2026-08-11*
