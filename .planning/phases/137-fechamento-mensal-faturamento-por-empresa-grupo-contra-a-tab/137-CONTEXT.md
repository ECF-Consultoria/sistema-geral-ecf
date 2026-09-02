# Phase 137: Fechamento mensal — faturamento por empresa/grupo contra a tabela progressiva - Context

**Gathered:** 2026-09-02
**Status:** Ready for planning

<domain>
## Phase Boundary

Dar ao Administrativo uma tela de fechamento que responda, para cada empresa e cada grupo, qual foi
o faturamento do **mês fechado** e em que faixa da tabela progressiva isso o coloca — subiu,
manteve ou caiu.

É a contrapartida operacional dos contratos das Fases 124-133: **o contrato define a tabela, o
fechamento a aplica.**

A rotina real acontece no **primeiro dia útil do mês, referente ao mês anterior** (em 01/09
fecha-se agosto) e cobre empresas de **Mercado Livre e Shopee**.

**Fora do escopo:** cobrar de fato, emitir nota, integrar com financeiro externo, mexer no cálculo
de desempenho/bônus.

</domain>

<decisions>
## Implementation Decisions

### Onde mora a tabela de faixas

- **D-01:** A tabela é **por serviço, com exceção por empresa**. A empresa herda a tabela do
  serviço dela; empresa com tabela fora do padrão ganha uma exceção própria, que vence sobre a do
  serviço. Descartado "sempre por empresa" (exigiria cadastrar ~190 tabelas antes do primeiro
  fechamento) e "só por serviço" (não representa as empresas fora do padrão, que existem e são
  legítimas).

- **D-02:** A tabela padrão do serviço **Gestão** é a que o sistema usa **hoje** — a que começa em
  **R$ 3.000** na primeira faixa (a constante `FAIXAS` de `AdminController`), **não** a do contrato
  atual (R$ 3.500). Decisão do usuário para não mexer no que já está sendo cobrado.

- **D-03:** ⚠️ **Consequência aceita, e ela cresce:** todo contrato de Gestão gerado pelo sistema
  hoje sai com R$ 3.500. Com D-02, **cada empresa nova precisa de uma exceção** para ficar certa —
  ou seja, o "padrão" passa a ser o caso minoritário com o tempo. O planner deve avaliar se a
  exceção pode nascer junto do contrato (o serviço e o valor já são conhecidos na geração) em vez
  de depender de cadastro manual a cada cliente novo.

- **D-04:** O cadastro manual da tabela por empresa é requisito explícito do usuário: "um jeito de
  cadastrar a tabela progressiva pelo sistema, como se estivesse fazendo contrato, mas só para o
  sistema saber as faixas". Precisa cobrir tabelas antigas/fora do padrão.

### De onde vem o faturamento

- **D-05:** Empresa que vende nas duas plataformas tem **ML + Shopee SOMADOS**, e a soma define
  **uma faixa única**. É o que o contrato combinado diz — o título da tabela é "Faturamento Mensal
  (Mercado Livre e Shopee)", uma tabela só para as duas.

- **D-06:** **Acaba o acumulativo.** Hoje o mês corrente usa janela MÓVEL de 30 dias
  (`Carbon::now()->subDays(30)`) e só meses passados usam mês-calendário. O usuário foi explícito:
  "não deve ter acumulativo — o valor mostrado deve ser de janelas fechadas, mês a mês". Toda
  competência é **mês-calendário fechado**.

- **D-07:** ⚠️ A Shopee **não tem consolidação mensal** hoje — `shopee_metrics` é diária (1.861
  linhas, 19 empresas) e `company_monthly_revenues` é alimentada **só pela Adman/ML**
  (`AdmanService::syncMonthRevenue`). O planner precisa resolver o rollup mensal de Shopee para o
  mesmo recorte de mês-calendário, senão empresa Shopee entra no fechamento sem faturamento.

### Como os grupos funcionam

- **D-08:** **Os grupos do Comercial (`CompanyGroup`) mandam.** `parent_company_id` deixa de valer
  no fechamento. Uma fonte de verdade só, como o usuário pediu. Medido: o mecanismo antigo cobre
  **5 empresas / 2 pais**; os grupos do Comercial cobrem **46 empresas / 15 grupos**.

- **D-09:** ⚠️ **Consequência conhecida e aceita:** `MOVELOVEOFICIAL` é pai de 3 empresas no
  mecanismo antigo mas **não tem grupo** no Comercial, e as 3 filhas estão espalhadas
  (`RELOJOARIA WENUS`→Wenus, `MPozenato`→MPozenato, `DESK DESIGN`→nenhum). Com D-08 ele **deixa de
  somar** com as três. Se estiver errado, o conserto é **no Comercial**, não no fechamento.
  (Camillo Parts não tem esse problema — pai e filhas estão todos no grupo. O grupo Lyam, exemplo
  do usuário, existe com 2 empresas.)

- **D-10:** O faturamento do grupo é a **soma das empresas-irmãs**, e é a soma que determina a
  faixa — subiu, manteve ou caiu.

### O fechamento vira registro

- **D-11:** **Congela ao fechar o mês.** Ao fechar a competência, o sistema **grava** faturamento,
  grupo, faixa aplicada e valor — por empresa e por grupo. Depois disso o número não muda sozinho.
  Mesmo padrão do snapshot de desempenho que o projeto já usa, e permite auditar o que foi cobrado
  e por quê.

- **D-12:** ⚠️ O usuário **não** escolheu a variante "congela mas dá para refazer". Não há, nesta
  fase, caminho para reabrir uma competência quando a Adman corrige um dado depois do fechamento.
  Ver `<deferred>`.

### Claude's Discretion

- Modelagem concreta das tabelas (nomes de tabela/coluna, se as faixas são linhas ou JSON).
- Como o rollup mensal de Shopee é produzido (comando agendado, job, view) — desde que respeite
  D-06 (mês-calendário) e D-07.
- Se a tela de fechamento é reescrita ou evoluída a partir de `AdminController::fechamento()`.
- Formato do "ver progressão" (o modal de histórico), desde que sem acumulativo.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### O que existe hoje e vai mudar

- `app/Http/Controllers/AdminController.php` — método `fechamento()` (~linha 126). É a tela atual,
  rota `/financeiro`. Contém a **janela móvel de 30 dias** (D-06) e a constante **`FAIXAS`**
  (~linhas 40-45), que é a tabela progressiva única de hoje (D-02).
- `app/Support/CobrancaCalculator.php` — `novo()` e `legacy()`; já calcula cobrança a partir de uma
  faixa. Ler antes de criar cálculo novo.
- `app/Models/CompanyMonthlyRevenue.php` + `AdmanService::syncMonthRevenue()` (~linha 1190) —
  faturamento por `year_month`, **só ML/Adman**. 571 linhas, 5 meses, ~129 empresas/mês.
- `app/Models/ShopeeMetric.php` — faturamento **diário** de Shopee, isolado do ML. Sem rollup mensal.
- `app/Models/FechamentoRecebido.php`, `app/Jobs/EnviarRelatorioFechamentoJob.php`,
  `app/Mail/RelatorioFechamentoMail.php` — **mapear antes de mexer**: a fase muda o que eles
  produzem.
- `app/Models/CompanyGroup.php` + `app/Http/Controllers/CompanyGroupController.php` — os grupos do
  Comercial que passam a mandar (D-08). Colunas: `id, name, color`.

### A tabela progressiva como está nos contratos

- `modelo-contrato-gestao-v5-PLATAFORMA.docx` (raiz) — tabela de Gestão/ML: **R$ 3.500** a
  R$ 12.000, 7 faixas.
- `modelo-contrato-shopee-COM-VARIAVEIS.docx` (raiz) — tabela de Shopee: **R$ 1.500** a R$ 5.000,
  8 faixas.
- `modelo-contrato-brigada-v3.docx` (raiz) — tabela de Brigada: **R$ 3.000** a R$ 12.000, 7 faixas.
- ⚠️ As três só existem como **texto** dentro dos `.docx`. O sistema **não** tem essas faixas como
  dado estruturado — é o que D-01 cria.

### Contexto das fases de contrato (o que já foi construído)

- `.planning/quick/260824-bte-pagamento-escalonado/SUMMARY.md` — consolidação de fases e o
  `servicos_snapshot` congelado (D-04 do contrato: valor vem do snapshot, nunca da tabela ao vivo).
- `.planning/quick/260825-fn0-plataforma-do-servico/SUMMARY.md` — `servicos.plataforma` e a
  disciplina de **ausência visível** (`A DEFINIR` + `campos_pendentes`), nunca branco silencioso.
- `.planning/quick/260901-gj7-contrato-combinado/SUMMARY.md` — `contrato_junto_com_servico_id`:
  ML + Shopee viram UM contrato. É a razão de D-05 somar as plataformas.

### Disciplina do projeto que se aplica aqui

- `.planning/learnings/desempenho-bonificacao.md` — **leitura obrigatória**: regras de agregação
  que divergem de propósito, e a disciplina de conferir consolidação por **reconsulta ao banco**,
  nunca por stdout.
- Memória do projeto: mês fechado lê **snapshot congelado**, não cálculo ao vivo — é o precedente
  direto de D-11.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`CompanyMonthlyRevenue`** — já é faturamento por mês fechado, com 5 meses de histórico. É a
  base natural do lado ML; falta o lado Shopee (D-07).
- **`CobrancaCalculator`** — já converte faixa em valor cobrado, com dois modos (`novo`/`legacy`).
- **`CompanyGroup`** — 15 grupos, 46 empresas, já mantido pelo Comercial. Nada a construir do lado
  de cadastro de grupo.
- **`FechamentoRecebido`** — já registra "recebido" por empresa e mês; pode ser o embrião do
  registro congelado de D-11 (avaliar antes de criar tabela nova).

### Established Patterns

- **Snapshot congelado por competência** — o módulo de desempenho já faz isso, e o projeto tem
  aprendizado registrado sobre não confiar em stdout para conferir consolidação.
- **Ausência visível** — placeholder `A DEFINIR` + campo em pendência, nunca valor em branco. Vale
  para empresa sem tabela cadastrada e para empresa sem faturamento.
- **Configuração por serviço lida em runtime** — `servicos.clicksign_template_id`,
  `plataforma`, `clicksign_assinatura_posicionada`, `contrato_junto_com_servico_id` seguem esse
  molde. A tabela de faixas por serviço (D-01) é o próximo membro dessa família.

### Integration Points

- `AdminController::fechamento()` é o ponto de entrada da tela.
- `AdmanService::syncMonthRevenue()` é quem popula o lado ML por mês.
- `Servico` é onde a configuração por serviço já vive.
- `Company::company_group_id` liga a empresa ao grupo do Comercial.

</code_context>

<specifics>
## Specific Ideas

- **Exemplo do usuário para grupo:** "o grupo Lyam, que tem duas empresas, seria somado o
  faturamento das duas e ver se atingiu alguma faixa a mais, se apenas se manteve na mesma ou se
  caiu." O grupo existe no Comercial com 2 empresas. Serve de caso de teste literal.
- **Rotina real:** fechamento no primeiro dia útil, referente ao mês anterior. O calendário de
  execução é isso — não é mês corrente.
- **A frase que define D-04:** "seria legal ter um jeito de conseguir adicionar modelo de tabela
  progressiva pelo sistema como se estivesse fazendo contrato, mas nesse caso o intuito seria
  apenas para o sistema saber as faixas de valores para cada empresa."

</specifics>

<deferred>
## Deferred Ideas

- **Reabrir/refazer uma competência já congelada** — foi apresentado como terceira opção em D-11 e
  **não** foi escolhido. O caso real existe (a Adman corrige dado depois do fechamento; há memória
  do projeto sobre gap de sync distorcendo baseline). Fica registrado: hoje não há caminho de
  correção pós-fechamento. Candidato a fase própria.
- **Descobrir quais empresas estão na tabela antiga vs. nova** — D-02 mantém R$ 3.000 como padrão,
  então em tese ninguém precisa mudar agora. Mas ninguém sabe, hoje, quais empresas de fato pagam
  qual tabela. Um levantamento seria necessário antes de confiar plenamente no valor calculado.
- **Cobrar de fato / integrar com financeiro** — o fechamento produz o número; emitir cobrança é
  outro assunto.
- **Corrigir os grupos no Comercial** (ex.: criar o grupo do MOVELOVEOFICIAL) — é ação do usuário
  no módulo Comercial, não código desta fase (D-09).

### Reviewed Todos (not folded)

- `260819-clicksign-erro-salvar-posicionamentos.md` — casou por palavra-chave (score 0.9), mas é de
  outra área. ⚠️ **Este todo está RESOLVIDO** pelos quicks `260824-mv3` (rubrica automática via
  API) e `260824-ot1` + `260825-c3m` (assinatura posicionada por tag). Alguém deveria fechá-lo.
- `260527-cleanup-suites-coexistencia.md` — casou por palavras genéricas ("phase", "planning"), sem
  relação com fechamento.

</deferred>

---

*Phase: 137-Fechamento mensal — faturamento por empresa/grupo contra a tabela progressiva*
*Context gathered: 2026-09-02*
