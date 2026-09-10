# Itens fora de escopo encontrados durante a execução — Fase 137

## `tests/Feature/FechamentoMigrationTest.php` falha desde antes desta fase

**Encontrado durante:** Plano 137-02, Tarefa 1 (verificação do gate).

`Tests\Feature\FechamentoMigrationTest::test_migration_adiciona_colunas` falha com
`Failed asserting that false is true` ao checar `Schema::hasColumn('companies', 'service_type')`
(e `contract_start`, `contract_end`, `additional_service`).

**Confirmado que NÃO é regressão desta fase:** removi temporariamente as 6 migrations novas
(as do plano 137-01 e as do 137-02) da pasta `database/migrations/` e rodei o teste isolado — a
falha persistiu igual, sem nenhuma migration nova presente. Arquivos restaurados em seguida, sem
perda.

Não investiguei a causa raiz (fora do escopo do plano 02: nenhuma das colunas testadas
pertence a `fechamento_snapshots`, `fechamento_grupo_snapshots` ou `fechamento_reconsolidacoes`).
Não faz parte do filtro de gate da fase (`Phase122|Phase136|Phase137`).

## `tests/Feature/AdminFechamentoControllerTest.php` — 5 falhas pré-existentes, não tocadas pelo plano 01

**Encontrado durante:** Plano 137-01, Tarefa 3 (verificação item 3 do bloco `<verification>` do
plano, que roda este teste isoladamente).

5 de 16 testes falham:

- `test_update_persiste_service_type`, `test_update_rejeita_service_type_invalido`,
  `test_update_persiste_datas_contrato`, `test_update_rejeita_contract_end_anterior` — todos
  testam `AdminController::updateFechamento()`, que desde a **Fase 14 Plano 14-06** só faz
  `return back();` (comentário no próprio código: "a gestão de serviços saiu deste endpoint e
  passou a usar exclusivamente as rotas de contratos de serviço"). Os 4 testes nunca foram
  atualizados para refletir isso — não é código tocado por este plano.
- `test_empresa_ok_recebe_periodo_coberto` — falha por data: o teste espera
  `periodo_inicio = Carbon::now()->startOfMonth()` (hoje, "01/09"), mas
  `AdminController::fechamento()` usa a janela MÓVEL de 30 dias para o mês corrente
  (`Carbon::now()->subDays(30)`, "03/08" em 2026-09-02) — é exatamente o comportamento
  registrado em D-06 do 137-CONTEXT.md ("Hoje o mês corrente usa janela MÓVEL de 30 dias... toda
  competência é mês-calendário fechado" — decisão que outra tarefa/plano desta fase corrige, não
  o 137-01, que só criou migrations e models de faixas).

**Confirmado que NÃO é regressão desta fase:** nenhum arquivo deste plano (as duas migrations de
tabela, o seed, e os 2 models novos) toca `AdminController.php`, suas rotas, ou qualquer coluna
lida por `updateFechamento()`/`fechamento()`. As linhas de `Company.php`/`Servico.php` alteradas
por este plano são só a adição do método `faixasFaturamento()` — não mexem em `$fillable`,
`$casts` nem em nenhum campo consultado por esses testes.

Não faz parte do filtro de gate da fase (`Phase122|Phase136|Phase137`) nem dos `files_modified`
do plano 137-01.

## `App\Models\BonusFaixa::getActivitylogOptions()` nunca loga nada — bug pré-existente, não corrigido

**Encontrado durante:** Plano 137-01, Tarefa 2 (prova do acceptance criteria "criar e alterar uma
linha grava em `activity_log`" — o mesmo padrão foi usado como molde para os models novos desta
fase, então o bug foi descoberto ao investigar por que os models novos também não logavam nada).

`LogOptions::defaults()` (Spatie) nasce com `logAttributes = []` e `logFillable = false` — sem
chamar `->logAll()`, `->logFillable()` ou `->logOnly([...])`, NENHUM atributo é rastreado, o log
gerado fica vazio e é descartado por `dontSubmitEmptyLogs()`. `BonusFaixa::getActivitylogOptions()`
(Fase 74) chama `->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName(...)` mas nunca
`->logFillable()`/`->logOnly([...])` — ou seja, **criar ou editar uma `BonusFaixa` hoje não grava
nenhuma linha em `activity_log`**, apesar do docblock da classe dizer "A trait LogsActivity
registra qualquer alteração para auditoria".

Confirmado empiricamente (teste descartável, não versionado): `BonusFaixa::create([...])` seguido
de `->update([...])` deixa a contagem de `activity_log` inalterada; o mesmo teste com
`ServicoFaixaFaturamento` só passou a gravar depois de eu adicionar `->logFillable()` no
`getActivitylogOptions()` dos dois models novos desta fase (`ServicoFaixaFaturamento` e
`EmpresaFaixaFaturamento` — ver commit da Tarefa 2 do plano 137-01).

**Não corrigi `BonusFaixa`** — é código da Fase 74, fora dos `files_modified` do plano 137-01, e
mexer nele é decisão de escopo de outra fase (a régua de bônus é sensível: qualquer PR nela
merece atenção própria, não um side-effect de descoberta). Fica registrado aqui para quem for
mexer em auditoria de bônus depois.

## `test_empresa_ok_recebe_periodo_coberto` — a falha sensível à janela móvel MUDOU de comportamento (esperado, D-06)

**Encontrado durante:** Plano 137-07, Tarefa 3 (baseline medida em stash antes de tocar nos testes,
conforme instruído).

O item acima ("5 falhas pré-existentes") já registrava que esta era a falha "sensível à janela
móvel de 30 dias" e que "outra tarefa/plano desta fase corrige" a causa raiz. O plano 137-07 É essa
correção: `fechamento()` agora usa `FechamentoRollupService::janela()` (mês-calendário fechado,
D-06) em vez de `Carbon::now()->subDays(30)`. Com a janela corrigida, `periodo_inicio` do mês
corrente passa a ser o dia 1 do mês — exatamente o que o teste sempre esperou — e o teste **passou
a verde sem eu tocar nele**.

Baseline medida (HEAD, antes das mudanças deste plano, restaurando temporariamente o arquivo via
`git show HEAD:...` e rodando de novo — nunca `git stash`, ver `destructive_git_prohibition`):
`AdminFechamentoControllerTest` — 5 falhas / 15 passou (215 asserções). Depois das mudanças deste
plano: 4 falhas / 12 passou (159 asserções) isolado, ou 4 falhas / 26 passou (281 asserções) rodando
junto com `Phase14FechamentoUiTest` + `Phase137FinanceiroPropsTest`. As 4 falhas remanescentes são
exatamente as 4 de `updateFechamento()`/`service_type`/`contract_start`/`contract_end` já descritas
acima — não tocadas, não fazem parte do filtro de gate da fase.

## `App\Models\ContratoServico` — dois observers rodam em cada `create()` de fixture de teste

**Encontrado durante:** Plano 137-07, Tarefa 3 (fixtures novas de `AdminFechamentoControllerTest` e
`Phase137FinanceiroPropsTest` que criam `ContratoServico` para dar à empresa um serviço "dono de
tabela", D-01).

`ContratoServico` tem `#[ObservedBy([ContratoServicoObserver::class, ContratoServicoGatilhoObserver::class])]`
(Fases 128/135) que criam onboarding em rascunho e reavaliam o gate administrativo a cada
`ContratoServico::create()`. Não observei nenhuma falha ou efeito colateral nos testes novos/
atualizados desta tarefa — registrado apenas porque é uma dependência implícita que qualquer
fixture nova de `ContratoServico` carrega, caso apareça lentidão ou side-effect inesperado depois.
