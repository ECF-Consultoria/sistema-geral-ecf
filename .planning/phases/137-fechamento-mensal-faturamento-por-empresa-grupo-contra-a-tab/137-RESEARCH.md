# Phase 137: Fechamento mensal — faturamento por empresa/grupo contra a tabela progressiva - Research

**Pesquisado em:** 2026-09-02
**Domínio:** Laravel/Eloquent — agregação financeira mensal, snapshot congelado, tabela de faixas configurável
**Confiança:** HIGH (todos os achados abaixo vêm de leitura direta do código, com arquivo:linha citado)

## Summary

O código hoje resolve fechamento com **duas fontes que já bastam** para D-06/D-07: `adman_metrics`
(diário, ML) e `shopee_metrics` (diário, Shopee) somados por `whereBetween`/`whereDate` na janela
do mês-calendário — exatamente o que `AdminController::fechamento()` já faz para o ramo "mês
passado" (`app/Http/Controllers/AdminController.php:141-149`) e o que `ShopeeMetricDiffService` já
faz para Shopee (`app/Services/Metrics/ShopeeMetricDiffService.php:104-116`, método `naJanela()`).
**O rollup mensal de Shopee (D-07) não precisa de infraestrutura nova** — precisa reusar essa query,
não inventar uma tabela de consolidação. O risco real de D-07 não é "como somar Shopee", é que
`company_monthly_revenues` (a tabela que a Progressão mensal já lê) fica com valor **rolling-30-dias
obsoleto** quando o mês vira, porque `adman:sync-faturamento` roda todo dia com `--mes=mês corrente`
e nada re-sincroniza o mês anterior como calendário fechado — achado que vale tanto para o
fechamento novo quanto para qualquer tela que já leia essa tabela.

`FechamentoRecebido` é fino demais para virar o snapshot congelado de D-11: tem só
`(company_id, mes, recebido_em)`, sem faturamento/faixa/valor, sem conceito de grupo, e sua unique
key não comporta uma segunda granularidade. O padrão certo para D-11 já existe no projeto —
`desempenho_company_score_snapshots` + `CompanyScoreSnapshotWriter` (Fase 122) — com trava de
congelamento por `origem`, upsert+prune idempotente, e um comando `verificar-consolidacao` de
reconsulta read-only. É o molde a copiar, com os nomes de arquivo concretos abaixo.

`AdminController::FAIXAS` está **duplicada** em `EnviarRelatorioFechamentoJob::FAIXAS`
(`app/Jobs/EnviarRelatorioFechamentoJob.php:33-40`), e o agrupamento por `parent_company_id`
(D-08 revoga) aparece em **4 lugares**: `fechamento()`, `gerarRelatorio()`, `gerarRelatorioGeral()`
e `EnviarRelatorioFechamentoJob`. Os quatro precisam mudar junto — nenhum lê de uma fonte central
hoje.

**Recomendação primária:** tratar a Fase 137 como (1) uma tabela de faixas configurável nos moldes
de `bonus_faixas` (Fase 74), (2) um comando `fechamento:consolidar-mes` nos moldes de
`desempenho:consolidar-mes` que grava em duas tabelas novas (empresa e grupo) via um writer
idempotente com trava de congelamento, lendo faturamento por `SUM(adman_metrics.revenue)` +
`SUM(shopee_metrics.revenue)` na janela de mês-calendário — sem tocar em `company_monthly_revenues`
— e (3) a migração dos 4 call-sites que hoje leem `AdminController::FAIXAS`/`parent_company_id`
para ler do snapshot congelado.

## Architectural Responsibility Map

| Capacidade | Camada primária | Camada secundária | Racional |
|------------|-----------------|--------------------|----------|
| Tabela de faixas por serviço/empresa (D-01/D-04) | API/Backend (Model + Controller CRUD) | — | Configuração estruturada, auditável (`LogsActivity`), sem lógica de UI própria — mesmo molde de `BonusFaixa` |
| Rollup de faturamento mensal ML+Shopee (D-05/D-06/D-07) | API/Backend (Service) | Database (query de agregação) | Cálculo determinístico sobre tabelas já existentes (`adman_metrics`, `shopee_metrics`); nenhuma chamada HTTP nova necessária para mês fechado |
| Agregação por grupo (D-08/D-10) | API/Backend (Service) | Database | `CompanyGroup::companies()` já existe; falta só o SUM sobre o rollup de faturamento por empresa |
| Congelamento por competência (D-11) | Database (tabelas de snapshot) | API/Backend (Command + Writer) | Mesmo padrão do módulo Desempenho — grava e nunca recalcula ao vivo depois de congelado |
| Tela `/financeiro` (leitura) | Frontend Server (Inertia/controller) | Browser (React) | Depois do congelamento, a tela deve ler o snapshot, não recalcular — mesma disciplina do `/performance` em mês fechado |
| Email mensal (`EnviarRelatorioFechamentoJob`) | API/Backend (Job) | — | Hoje monta os dados do zero (duplicando `FAIXAS` e a lógica de `parent_company_id`); deve passar a ler o snapshot congelado, não recalcular |

## Standard Stack

Nenhuma biblioteca nova. A fase é 100% Eloquent/Laravel + Inertia/React já em uso. Sem `composer
require`/`npm install` — **Package Legitimacy Audit não se aplica** (nenhum pacote externo entra).

### Reaproveitável do próprio projeto

| Peça | Onde | Papel na Fase 137 |
|------|------|--------------------|
| `MetricPeriodResolver::resolve(['period_key' => $mes])` | `app/Services/Metrics/MetricPeriodResolver.php:225` (`resolveSpecificMonth`) e `:185` (`resolveLastClosedMonth`) | Já devolve `current_start`/`current_end` de mês-calendário fechado — é literalmente D-06 pronto, sem reescrever Carbon à mão |
| `ShopeeMetricDiffService::compute()` | `app/Services/Metrics/ShopeeMetricDiffService.php:80` | `metrics.revenue.value` = SUM de `shopee_metrics.revenue` na janela — é o rollup de D-07, já cacheado por dia |
| `CobrancaCalculator::novo()`/`legacy()` | `app/Support/CobrancaCalculator.php` | Converte faixa→valor de cobrança; não decide a faixa em si (isso é `calcularFaixa()`, que muda com D-01) |
| `CompanyGroup::companies()` (HasMany) | `app/Models/CompanyGroup.php:20` | Fonte de verdade dos grupos (D-08); nenhum CRUD de grupo a construir |
| `BonusFaixa` + migration | `app/Models/BonusFaixa.php`, `database/migrations/2026_07_09_140002_create_bonus_faixas_table.php` | Molde de tabela de faixas configurável com `LogsActivity`, `ativas()`/`ordenadas()` scopes — é o precedente de D-01/D-04 |
| `CompanyScoreSnapshotWriter` + `DesempenhoCompanyScoreSnapshot` | `app/Services/Desempenho/CompanyScoreSnapshotWriter.php`, migration `2026_08_03_120000_...` | Molde do writer idempotente com trava de congelamento por `origem` — é o precedente de D-11 |
| `VerificarConsolidacaoDesempenho` | `app/Console/Commands/VerificarConsolidacaoDesempenho.php` | Molde do comando de reconsulta read-only (`--json`, exit code como veredito) — aplica a disciplina "nunca confiar em stdout" |

## Don't Hand-Roll

| Problema | Não construa | Use em vez disso | Por quê |
|----------|---------------|-------------------|---------|
| Janela de mês-calendário fechado | Nova lógica de `Carbon::startOfMonth()/endOfMonth()` espalhada | `MetricPeriodResolver::resolve()` | Já existe, já testado, já usado por Carteira e Desempenho; reescrever cria uma terceira implementação da mesma regra |
| Rollup mensal de Shopee | Job/command que materializa uma tabela `shopee_monthly_revenues` nova | `SUM(shopee_metrics.revenue)` na janela (mesma query de `ShopeeMetricDiffService::naJanela()`) | `shopee_metrics` já é a fonte; criar tabela intermediária duplica dado e adiciona mais uma coisa para re-sincronizar |
| Congelamento por competência | Reaproveitar/inchar `FechamentoRecebido` com colunas novas | Tabela(s) nova(s) no molde `desempenho_company_score_snapshots` | `FechamentoRecebido` é um flag booleano com unique `(company_id, mes)` — não comporta granularidade de grupo nem histórico de campos numéricos sem quebrar sua unique key atual |
| Verificação de que o fechamento congelou certo | Confiar no texto impresso pelo comando de consolidação | Comando de reconsulta `--json` + exit code, no molde de `VerificarConsolidacaoDesempenho` | É a disciplina que o projeto já pagou caro para aprender (`.planning/learnings/desempenho-bonificacao.md` §4 e §10.1) |

**Insight-chave:** as duas peças que parecem faltar (rollup mensal de Shopee e "onde congelar") já
têm gêmeo no código. O trabalho real da fase é *ligar* essas peças com a tabela de faixas nova e o
mecanismo de grupo novo — não inventar do zero.

---

## 1. Rollup mensal da Shopee (D-07)

**Achado principal (HIGH, medido):** `AdmanService::syncMonthRevenue()` NÃO é a fonte certa a
replicar para Shopee, e também tem um problema próprio que a Fase 137 herda se usar
`company_monthly_revenues` como está.

### O que `syncMonthRevenue` faz de fato

`app/Services/AdmanService.php:1190-1219`:
```php
if ($ref->isSameMonth(Carbon::now())) {
    $start = Carbon::now()->subDays(30)->toDateString();   // rolling 30d
    $end   = Carbon::now()->toDateString();
} else {
    $start = $ref->toDateString();                          // mês-calendário completo
    $end   = $ref->copy()->endOfMonth()->toDateString();
}
```
Isso é chamado por `SyncFaturamentoMensalJob` (`app/Jobs/SyncFaturamentoMensalJob.php:28`), que é
disparado pelo comando `adman:sync-faturamento` (`app/Console/Commands/SyncFaturamentoMensal.php`).
**Esse comando roda agendado todo dia às 11:30 BRT com `--mes=` padrão = mês CORRENTE**
(`routes/console.php:103-106`, `SyncFaturamentoMensal.php:18-20`: `Carbon::now()->format('Y-m')`
quando `--mes` não é passado).

⚠️ **Consequência não-óbvia, achado desta pesquisa:** quando o mês vira, o comando agendado passa a
rodar com `--mes=` = novo mês. **Ninguém re-sincroniza o mês que acabou de fechar com a janela
calendário completa.** A última escrita em `company_monthly_revenues` para o mês que fechou foi
feita enquanto ele ainda era "mês corrente" — ou seja, com a janela rolling de 30 dias do dia em que
rodou por último, não o mês-calendário completo real. Hoje isso não quebra nada porque **a tela
`fechamento()` NÃO lê `company_monthly_revenues` para o valor principal de mês passado** — ela soma
`adman_metrics` diretamente (ver abaixo). Só a "Progressão mensal" (modal de histórico) lê
`company_monthly_revenues`, e portanto **herda esse valor potencialmente incompleto** para o
último mês antes de alguém clicar manualmente em "Sincronizar" (`syncFaturamento()`,
`AdminController.php:390-425`) apontando para o mês certo.

**Implicação para D-06/D-11:** se a Fase 137 usar `company_monthly_revenues` como fonte do
congelamento, herda esse gap — precisaria disparar um re-sync explícito do mês fechado antes de
congelar (custo: 1 chamada HTTP à Adman por empresa, como o botão manual já faz). **Se em vez disso
usar `SUM(adman_metrics.revenue)` na janela do mês-calendário (o padrão que `fechamento()` já usa
para mês passado), o problema desaparece** — `adman_metrics` é alimentado diariamente por
`adman:sync` (D-1, `routes/console.php:15`), então no primeiro dia útil do mês seguinte todos os
dias do mês fechado já estão gravados localmente, sem HTTP novo.

### Como replicar para Shopee — dois caminhos equivalentes

**Caminho A — reusar o service existente (recomendado, HIGH confidence):**
```php
$periodo = app(MetricPeriodResolver::class)->resolve(['period_key' => $mesFechado]); // 'YYYY-MM'
$shopee  = app(ShopeeMetricDiffService::class)->compute($company, $periodo);
$faturamentoShopee = $shopee['metrics']['revenue']['value']; // já é SUM(shopee_metrics.revenue) na janela
```
Fonte: `app/Services/Metrics/ShopeeMetricDiffService.php:80-100` (método `compute`) e `:186-194`
(`calcularRevenue`). O `cacheKey` é por dia (`shopee:diff:v2:...`), TTL 1440min — seguro para mês
fechado (não muda).

**Caminho B — query direta, sem passar pelo service (mais simples, mesmo resultado):**
```php
ShopeeMetric::where('company_id', $company->id)
    ->whereDate('reference_date', '>=', $inicioMes)
    ->whereDate('reference_date', '<=', $fimMes)
    ->sum('revenue');
```
É exatamente `naJanela()` (`ShopeeMetricDiffService.php:159-167`) inline. Evita a dependência do
shape de `compute()` (que inclui `contribution_margin_*` sempre null e `investment`, que a Fase 137
não usa).

**`company_monthly_revenues` não comporta uma segunda fonte hoje.** `CompanyMonthlyRevenue`
(`app/Models/CompanyMonthlyRevenue.php`) tem `gross_revenue` como coluna única por
`(company_id, year_month)` — não existe `platform`/`source`. Não é recomendado forçar Shopee para
dentro dessa tabela (mudaria semântica de uma tabela já lida por `fechamento()`/progressão). A soma
ML+Shopee (D-05) deve acontecer **no momento do congelamento**, não numa tabela intermediária: leia
os dois números separadamente (ML de `adman_metrics`, Shopee de `shopee_metrics`) e some no
snapshot.

**Não usar `CarteiraContextService::flagsFinanceirasPorSetor()` para decidir "essa empresa é
ML+Shopee".** Esse método resolve fonte financeira **por vínculo de profissional** (evita dupla
contagem de bônus entre analistas — `app/Services/Portfolio/CarteiraContextService.php:247-276`),
não "essa empresa vende nas duas plataformas". Para D-05, o sinal certo é **dados presentes**: a
empresa tem `adman_metrics`/`ml_store_id`/`adman_account_id` com receita no mês E `shopee_metrics`
com receita no mês → soma as duas. Usar o resolvedor de vínculo aqui misturaria uma regra pensada
para bônus individual com uma regra de faturamento por empresa — problemas diferentes.

---

## 2. `FechamentoRecebido` como base do snapshot congelado (D-11)

**Não dá para evoluir — precisa de tabela(s) nova(s).** Medido:

```php
// app/Models/FechamentoRecebido.php
class FechamentoRecebido extends Model {
    public $timestamps = false;
    protected $fillable = ['company_id', 'mes', 'recebido_em'];
}
```
```php
// database/migrations/2026_05_19_200001_create_fechamento_recebidos_table.php
$table->foreignId('company_id')->constrained()->cascadeOnDelete();
$table->string('mes', 7);
$table->timestamp('recebido_em')->useCurrent();
$table->unique(['company_id', 'mes']);
```

Três motivos concretos:
1. **Sem coluna nenhuma de faturamento/faixa/valor** — teria que ganhar ~6 colunas novas
   (faturamento_ml, faturamento_shopee, faixa, valor, evolução) misturadas com o propósito atual
   (flag de "recebeu o pagamento"), que é semanticamente outra coisa (estado operacional, editável
   por clique — `AdminController::toggleRecebido()`, linha 434-450 — versus fato histórico
   congelado, imutável).
2. **Unique key `(company_id, mes)` não comporta GRUPO.** D-11 exige congelar por empresa E por
   grupo — duas granularidades. Forçar as duas na mesma tabela exigiria uma coluna
   discriminadora + FK nullable dupla (`company_id` nullable, `company_group_id` nullable) — possível,
   mas foge do padrão que o projeto já usa (tabelas separadas por granularidade: compare
   `desempenho_score_snapshots`, por user, com `desempenho_company_score_snapshots`, por empresa —
   Fase 74 vs Fase 122).
3. **`toggleRecebido()` apaga a linha ao desmarcar** (`AdminController.php:434-450`,
   `$recebido->delete()`). Um registro de auditoria (D-11 diz "valor entra em contrato e precisa
   ser auditável") não pode ter esse comportamento — apagar destruiria o histórico que D-11 exige
   preservar.

**Recomendação (Claude's Discretion, D-01 nota explicitamente):** duas tabelas novas, no molde de
`desempenho_company_score_snapshots`:
- `fechamento_snapshots` — 1 linha por `(company_id, mes_referencia)`: faturamento_ml,
  faturamento_shopee, faturamento_total, servico_id (qual tabela de faixas foi aplicada),
  faixa_aplicada, valor_faixa, evolução (subiu/manteve/caiu), `origem`, `gerado_em`.
- `fechamento_grupo_snapshots` — 1 linha por `(company_group_id, mes_referencia)`: mesma forma,
  mas `faturamento_total` é a SOMA das empresas do grupo naquela competência (lida do snapshot por
  empresa já congelado, não recalculada do zero — evita divergência entre os dois números).

`FechamentoRecebido` **permanece intocada** — continua sendo o flag operacional de "já pagou",
agora referenciando (opcionalmente) a competência já congelada em vez de recalcular a faixa.

---

## 3. O precedente de snapshot congelado (padrão a seguir)

Módulo Desempenho, Fases 74 (resumo por user) e 122 (detalhe por empresa) — **é o molde exato para
D-11**, com nomes de arquivo concretos:

### Comando (`app/Console/Commands/ConsolidarMesDesempenho.php`)
- Signature: `{--mes= : YYYY-MM (default = mês anterior ao hoje)}`.
- **Nunca `createFromFormat('Y-m', ...)`** sem o dia — estoura para o mês seguinte quando o mês
  alvo tem menos dias que hoje. Sempre `createFromFormat('Y-m-d', $mesOption.'-01')->startOfMonth()`
  (comentário explícito na classe, linhas 118-127, e repetido em
  `VerificarConsolidacaoDesempenho.php`).
- Idempotência via `updateOrCreate(['user_id', 'mes_referencia'])` — rerun no mesmo mês não duplica.
- **Gate de qualidade antes de persistir** (FIXMARG-03): se a amostra estiver degradada, a escrita é
  **recusada**, preservando o snapshot anterior, com `Log::error` nomeando o impacto — nunca grava
  linha degradada por cima de uma boa.

### Writer (`app/Services/Desempenho/CompanyScoreSnapshotWriter.php`)
- Único ponto de escrita da tabela de detalhe — todo comando que grava passa por `sync()`, nunca
  `updateOrCreate` direto no controller/command.
- **Trava de congelamento por `origem`** (linhas 58-72): escrita de origem "provisória" nunca
  sobrescreve uma competência já gravada pela origem "oficial"; a origem oficial ignora a trava de
  propósito (reconsolidar é o caminho suportado).
- `sync()` é **upsert + prune numa transação** — convergir para o conjunto atual, nunca
  insert-only (linhas 130-150 aprox., prune deleta o que saiu do conjunto).
- Usa `whereDate()` em vez de comparar a coluna `date`-cast crua — pitfall documentado no próprio
  código (comentário nas linhas ~112-119: `updateOrCreate` com a data crua nunca bate porque o cast
  grava datetime completo).

### Verificação (`app/Console/Commands/VerificarConsolidacaoDesempenho.php`)
- **Read-only, `--json`, o veredito é o exit code** — nunca o texto impresso (mesma disciplina do
  `.planning/learnings/desempenho-bonificacao.md` §4).
- Compara as DUAS tabelas entre si (resumo × detalhe) para achar 5 classes de inconsistência
  nomeadas (`SEM_SNAPSHOT`, `SEM_LINHAS`, `LINHAS_ORFAS`, `DIVERGENCIA_*`, `ORIGEM_NAO_CONGELADA`).

**Para a Fase 137, o equivalente é:**
- `fechamento:consolidar-mes {--mes=}` — grava `fechamento_snapshots` (por empresa) e
  `fechamento_grupo_snapshots` (por grupo, somando os snapshots de empresa já gravados, não
  recalculando do zero).
- `FechamentoSnapshotWriter` — mesma trava de congelamento e upsert+prune.
- `fechamento:verificar-consolidacao {--mes=} {--json}` — read-only, exit code como veredito.
- **Nomes de índice explícitos e curtos** (ver Pitfall de 64 caracteres abaixo) — a migration de
  `desempenho_company_score_snapshots` já resolveu isso com `dcss_user_company_mes_unique`
  (`database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php:94`);
  copiar a convenção, não repetir o índice default do Laravel.

⚠️ **D-12 já descarta reabertura** — mas note que o precedente do Desempenho (`consolidar_mes`
ignora a trava de propósito) já é, de fato, a variante "congela mas dá para refazer" que D-12
recusou para esta fase. Isso é uma divergência deliberada de D-12 em relação ao molde que a fase
está copiando — vale deixar explícito no plano que o comando de consolidação da Fase 137, ao contrário
do do Desempenho, **não deve permitir re-execução sobre uma competência já congelada** sem uma flag
separada e explícita (ou não deve ser reexecutável, ponto — depende de como o planner interpretar
D-12 literalmente). Se o comando permitir `--mes=` para qualquer mês passado sem trava nenhuma, ele
na prática reabre — o que D-12 explicitamente não escolheu.

---

## 4. Impacto em quem consome o fechamento hoje

Quatro locais leem `AdminController::FAIXAS`/`parent_company_id`/`FechamentoRecebido` hoje — nenhum
lê de uma fonte central:

| Call-site | Arquivo:linha | O que produz hoje | O que quebra com D-06 (mês-calendário) + D-08 (grupos) |
|-----------|----------------|---------------------|----------------------------------------------------------|
| `fechamento()` | `AdminController.php:126-419` | Tela `/financeiro` — janela rolling 30d no mês corrente (linha 141-149), agregação por `parent_company_id`/filhas (linha 351-380) | Precisa parar de usar rolling 30d (D-06) e trocar a soma `filhaIds`/`filhas` por soma via `CompanyGroup` |
| `gerarRelatorio()` | `AdminController.php:453-587` | PDF por empresa (view Blade `admin.relatorio-fechamento`) — mesma janela rolling + `$company->filhas` | Mesma troca — usa `$company->filhas` (parent_company_id) na linha ~488 |
| `gerarRelatorioGeral()` | `AdminController.php:590-716` | Relatório geral (view Blade `admin.relatorio-geral`) — mesma janela + `$company->filhas` | Mesma troca — usa `whereNull('parent_company_id')` como filtro raiz (linha ~625) |
| `EnviarRelatorioFechamentoJob` | `app/Jobs/EnviarRelatorioFechamentoJob.php` | Email mensal (dia configurável, default dia 5 09:00 — `routes/console.php:260-278`), anexa PDF via `RelatorioFechamentoMail` (Browsershot) | **Duplica `FAIXAS` inteira** (linhas 33-40) e a lógica de `parent_company_id`/filhas (linha ~120); soma só `adman_metrics`, **nunca incluiu Shopee** — gap pré-existente que D-05 também precisa fechar aqui, não só na tela |

**Quem dispara `EnviarRelatorioFechamentoJob`:**
- Manual: `AdminController::enviarRelatorioGeral()` → `dispatchSync()` (`AdminController.php:718-736`).
- Automático: `Schedule::call()` em `routes/console.php:260-278`, roda `everyMinute()` e só age
  quando `hoje == dia configurado E hora:minuto == hora configurada` (config via
  `Configuracao::get('email_envio_auto_dia', '5')`/`'email_envio_auto_hora', '09:00'`).

⚠️ **Divergência com o calendário real da rotina descrito no CONTEXT** ("primeiro dia útil do mês,
referente ao mês anterior"): o **default** configurado é dia 5 às 09:00, não "primeiro dia útil".
Isso é ajustável pela tela `Admin/ConfiguracoesFinanceiro.jsx` sem código — não é um bug, é uma
config que pode já estar diferente do default em produção. Vale confirmar com o usuário/Administrativo
qual valor está configurado hoje antes de assumir que "dia 1" já é o comportamento real.

**Não existe rota/comando "fechar a competência"** separado da tela hoje — `fechamento()` sempre
calcula ao vivo (para "mês atual") ou por `AdmanMetric` direto (para "mês passado"), nunca lê um
snapshot. A Fase 137 introduz esse conceito do zero; nenhum consumidor precisa ser "migrado de um
snapshot para outro" — é a primeira vez que existe.

---

## 5. Modelagem das faixas (D-01)

**Precedente direto: `bonus_faixas`** (Fase 74) — tabela de régua configurável, com CRUD admin,
`LogsActivity` para auditoria, e scopes de conveniência. Arquivos:
- Migration: `database/migrations/2026_07_09_140002_create_bonus_faixas_table.php`
- Seed idempotente: `database/migrations/2026_07_09_140003_seed_bonus_faixas_iniciais.php`
  (`updateOrInsert(['slug' => ...])`)
- Model: `app/Models/BonusFaixa.php`
- Controller CRUD: `app/Http/Controllers/DesempenhoConfigController.php`
- FormRequest de validação: `app/Http/Requests/UpdateBonusFaixaRequest.php`

Schema de `bonus_faixas`: `slug` (chave estável, não editável), `nome`/`descricao` (editáveis),
`nota_min`/`nota_max` (limites da faixa), `ordem`, `ativo` (soft-disable, preserva histórico),
índice composto `(ativo, ordem)` nomeado explicitamente.

### Diferença que a Fase 137 precisa resolver e `bonus_faixas` não tinha: herança com exceção

D-01 é "por serviço, com exceção por empresa" — `bonus_faixas` é uma régua única (não tem dimensão
de herança). A modelagem concreta recomendada (Claude's Discretion, mas com evidência de dois
precedentes no projeto):

**Duas tabelas, linhas por faixa (não JSON):**
```
servico_faixas_faturamento
  id, servico_id (FK), ordem, limite_superior (decimal, null = "sem teto"), valor, timestamps

empresa_faixas_faturamento
  id, company_id (FK), ordem, limite_superior (decimal, null = "sem teto"), valor, timestamps
```
Resolução: `Company` ganha um método `faixasFaturamento()` que devolve
`empresa_faixas_faturamento` se existir alguma linha para aquela empresa, senão cai para
`servico_faixas_faturamento` do serviço de Gestão/ML ativo da empresa — mesmo padrão de "herança
com override" que `CobrancaCalculator` já usa entre `valor_padrao` do serviço e
`valor_contratado` do contrato (`app/Models/ContratoServico.php`, comentário de docblock: "permite
override do valor_padrao do catálogo").

**Por que linhas e não JSON:** o precedente de `bonus_faixas` já decidiu isso explicitamente — a
migration documenta "a UI de CRUD e a query dinâmica exigem tabela dedicada com colunas fortemente
tipadas" (comentário na migration, linha ~15) e rejeitou key-value. D-04 pede uma UI de cadastro
("como se estivesse fazendo contrato") — linhas tipadas permitem validação de sobreposição (como
`UpdateBonusFaixaRequest` já faz) e edição individual por faixa; um blob JSON exigiria reimplementar
essa validação a mão.

**Auditoria:** ambas as tabelas precisam de `LogsActivity`, seguindo o padrão de `BonusFaixa` — D-01
nota que "o valor da faixa entra em contrato e precisa ser auditável", e o projeto já tem a
convenção pronta (`getActivitylogOptions()` com `logOnlyDirty()`).

**D-03 (a consequência que cresce):** a pesquisa não resolve D-03 (nasce exceção junto com o
contrato ou não) — é decisão de produto/planner. Mas o gancho de código já existe:
`ContratoServicoObserver` e `ContratoServicoGatilhoObserver` já disparam automaticamente quando um
`ContratoServico` nasce (`app/Models/ContratoServico.php`, atributo `#[ObservedBy(...)]`) — um
terceiro observer (ou lógica dentro de um existente) poderia criar a linha de
`empresa_faixas_faturamento` no mesmo evento, se o planner decidir que a exceção nasce automática.
É o mesmo padrão arquitetural já em uso, não uma ideia nova.

---

## Common Pitfalls

### Pitfall 1: Índice/constraint acima de 64 caracteres (MariaDB erro 1059) — medido, não hipotético
**O que acontece:** MariaDB recusa nomes de índice/constraint acima de 64 caracteres; a migration
fica com a tabela criada e o índice **faltando**, mas o Laravel marca a migration como rodada.
**Medido nesta pesquisa:** um nome de tabela plausível para o snapshot congelado —
`fechamento_faturamento_snapshots` — combinado com `unique(['company_id', 'mes_referencia'])` gera
o nome default `fechamento_faturamento_snapshots_company_id_mes_referencia_unique`, que tem
**65 caracteres** (verificado com `wc -c`), 1 acima do limite. SQLite dos testes não recusa —
já aconteceu de verdade no projeto (`.planning/learnings/desempenho-bonificacao.md` §10.1, o
incidente de julho/2026 que ficou 2 meses sem fechar por essa exata causa).
**Como evitar:** nomear tabelas curtas (`fechamento_snapshots`, não
`fechamento_faturamento_snapshots`) e, mesmo assim, **sempre** passar nome explícito de índice —
`$table->unique([...], 'fechamento_snap_empresa_mes_unique')` — como
`desempenho_company_score_snapshots` já faz (`dcss_user_company_mes_unique`).
**Sinal de alerta:** `SHOW INDEX FROM <tabela>` no MariaDB de produção não mostra o índice esperado
mesmo com a migration como "Ran" — é o único jeito confiável de detectar (§10.1 do learnings).

### Pitfall 2: FK `nullOnDelete` sem `nullable()` (MariaDB erro 1830)
**Onde vale para esta fase:** se `empresa_faixas_faturamento.company_id` ou
`fechamento_grupo_snapshots.company_group_id` usarem `nullOnDelete()`, a coluna precisa de
`->nullable()` explícito ANTES — senão SQLite (testes) aceita e MariaDB (produção) recusa no
deploy. Precedente já resolvido corretamente em `company_groups`
(`database/migrations/2026_06_11_120000_create_company_groups_table.php:23-27`, `nullable()` vem
antes de `constrained()->nullOnDelete()`) e documentado como erro conhecido em
`.planning/learnings/desempenho-bonificacao.md` §6.
**Decisão de design que evita o problema:** se o snapshot de empresa deve **sumir** quando a
empresa é apagada (não sobreviver órfão), use `cascadeOnDelete()` com coluna NOT NULL — é o padrão
de `desempenho_company_score_snapshots.company_id` (linha 33-34 da migration, comentário explícito:
"deliberadamente NÃO usa o modificador que zera a coluna no delete... Se o profissional/empresa for
removido, a linha de fato some junto"). Provavelmente o comportamento certo para
`fechamento_snapshots` também — snapshot de empresa apagada não tem valor de auditoria.

### Pitfall 3: Dropar índice usado por FK falha (MariaDB erro 1553)
**Onde vale:** só se a Fase 137 alterar uma tabela existente que já tem FK (ex.: adicionar coluna a
`fechamento_recebidos`). Como a recomendação acima é **não mexer** em `fechamento_recebidos` e criar
tabelas novas, este pitfall provavelmente não se aplica — mas se o planner decidir alterar um unique
existente em qualquer tabela tocada, crie o índice novo ANTES de dropar o antigo
(`.planning/learnings/desempenho-bonificacao.md` §6, e o incidente real documentado em §10.1 é
exatamente essa ordem invertida).

### Pitfall 4: Enum via migration quebra o SQLite dos testes
**Onde vale:** se qualquer coluna nova usar lista fixa de valores (ex.: `evolucao` = subiu/manteve/
caiu, `origem` do snapshot), **use `string()`, nunca `enum()`**. `desempenho_company_score_snapshots`
já resolveu isso — `fonte_financeira`/`status` são `string()` com comentário explícito: "STRING
sempre — nunca coluna de tipo restrito por lista fixa: o CHECK é enforçado no SQLite dos testes e
quebra ao surgir valor novo" (migration, linha ~44-47).

### Pitfall 5: Divergência de propósito entre agregações — NÃO uniformizar
`.planning/learnings/desempenho-bonificacao.md` §1 registra que o Desempenho usa **mediana** para
faturamento e **média** para margem, de propósito, e que isso não deve ser "corrigido" para
uniformidade. **Não colide diretamente com a Fase 137** porque D-10 já decide explicitamente que o
faturamento do grupo é **SOMA** (não média nem mediana) das empresas-irmãs — é uma terceira regra de
agregação, própria desta fase, e está corretamente travada por decisão do usuário. Only mencionar
para o planner **não reusar** `computeVarFaturamento()`/`median()` do `DesempenhoScoreService` aqui —
são dois problemas diferentes (variação percentual da carteira de um profissional vs. faturamento
absoluto somado de um grupo de empresas) que coincidentemente compartilham a palavra "faturamento".

### Pitfall 6: Conferir consolidação por stdout, não por reconsulta
`.planning/learnings/desempenho-bonificacao.md` §4 e §10.1: o gate de qualidade do
`desempenho:consolidar-mes` reportou sucesso (exit code 0) por semanas enquanto 11 de 12 rows
falhavam silenciosamente (erro engolido por `try/catch (\Throwable)`). Se a Fase 137 seguir o mesmo
padrão de comando + gate, **o comando de consolidação da Fase 137 precisa retornar exit code
correspondente à falha real**, e o plano precisa incluir o comando de verificação read-only (item 3
acima) como parte do critério de "fase funciona", não como nice-to-have.

### Pitfall 7: `AdmanMetricDiffService` não é a fonte certa para ML no fechamento
Diferente de `ShopeeMetricDiffService` (100% leitura local), `AdmanMetricDiffService`
(`app/Services/Metrics/AdmanMetricDiffService.php:52-144`) é **HTTP-first** — lê `.diff`/`.value` ao
vivo da API da Adman, com fallback local só quando a API falha, e é orientado a variação percentual
para bônus (não a faturamento absoluto). Usá-lo para o fechamento faria uma chamada HTTP
desnecessária por empresa todo dia 1 e traria uma semântica (diff/variação) que a Fase 137 não
precisa. Use `SUM(adman_metrics.revenue)` direto — é o que `fechamento()` já faz para mês passado
(`AdminController.php:184-191`) e não depende de rede.

## Validation Architecture

### Test Framework
| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.x, config `phpunit.xml` |
| DB de teste | SQLite `:memory:` (`phpunit.xml:27-28`) |
| Comando rápido | `C:\xampp\php\php.exe artisan test --filter=Fechamento` |
| Comando completo | `C:\xampp\php\php.exe artisan test` |

### Testes existentes que a Fase 137 vai quebrar/precisar tocar
| Arquivo | O que cobre hoje | Por que muda |
|---|---|---|
| `tests/Feature/AdminFechamentoControllerTest.php` | `test_fatura_ate_499k_retorna_faixa_correta`, `test_fatura_500k_999k_retorna_faixa_correta`, `test_fatura_acima_5m_retorna_maxima`, `test_metrica_fora_do_mes_nao_conta` (linhas 201-266) | Testa `AdminController::FAIXAS` hardcoded — passa a testar a tabela `servico_faixas_faturamento` (D-01/D-02: valores continuam batendo, R$ 3.000 na faixa 1, mas a FONTE muda de constante para DB) |
| `tests/Feature/FechamentoMigrationTest.php` | 20 linhas — provavelmente cobre a migration de `fechamento_recebidos` | Ler antes de decidir se `FechamentoRecebido` é tocada |
| `tests/Feature/Phase14FechamentoUiTest.php` | UI/props de `Admin/Financeiro.jsx` via `contratosServico` | Props da tela mudam se a agregação de grupo trocar de `filhas`/`parent_company_id` para `CompanyGroup` |

### Requisitos da fase → testes (a preencher pelo planner com REQ-IDs concretos)
Não há REQUIREMENTS-vNN.md com IDs para esta fase ainda (fase avulsa, fora de milestone — mesmo
padrão de 134/135/136, ver `.planning/STATE.md` linha ~12-13). O planner deve gerar os REQ-IDs no
PLAN.md; a tabela de mapeamento REQ→teste só pode ser preenchida nesse momento.

### Wave 0 Gaps
- [ ] Migration + factory para `servico_faixas_faturamento`/`empresa_faixas_faturamento`
- [ ] Migration + factory para `fechamento_snapshots`/`fechamento_grupo_snapshots`
- [ ] `tests/Feature/Fechamento/ConsolidarMesFechamentoTest.php` — idempotência (rerun não duplica),
      trava de congelamento, soma ML+Shopee, soma de grupo
- [ ] `tests/Feature/Fechamento/VerificarConsolidacaoFechamentoTest.php` — read-only, `--json`, exit
      code
- [ ] Teste de regressão explícito para o Pitfall 1 (nome de índice): migration precisa rodar em
      MariaDB real ou o teste precisa medir o comprimento do nome de índice gerado

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|--------|--------------------|
| A1 | A modelagem recomendada (duas tabelas de linhas, herança empresa > serviço) é a melhor forma de D-01 — CONTEXT deixa "modelagem concreta" a critério do Claude, então isto é recomendação, não fato verificado com o usuário | §5 | Se o usuário preferir JSON por faixa ou uma tabela única com discriminador, o schema muda — mas a exigência de auditoria (D-04) e o precedente `bonus_faixas` pesam fortemente a favor de linhas tipadas |
| A2 | O default de agendamento do email (dia 5, 09:00) é o valor REALMENTE configurado em produção hoje | §4 | Não verificado — é só o default do código (`Configuracao::get(..., '5')`/`'09:00'`); o admin pode ter mudado via `Admin/ConfiguracoesFinanceiro.jsx`. Não bloqueia o planejamento, mas o planner não deve assumir "dia 1" sem confirmar |
| A3 | Nenhuma empresa hoje tem receita simultânea ML+Shopee suficiente para já ter sido testada em produção (D-05 é caminho novo) | §1 | Não medido (banco local indisponível nesta sessão — MariaDB não estava rodando). Se muitas empresas já somam as duas, o "caso de teste literal" do grupo Lyam pode não ser o único a validar antes de deploy |

**Se esta tabela precisar ficar mais longa:** nada além disso ficou sem verificação de código —
o restante das claims tem arquivo:linha citado diretamente.

## Open Questions

1. **Qual `--mes=` é aceito pelo comando de consolidação, e ele pode reprocessar um mês já
   congelado?**
   - O que sabemos: D-12 recusa "congela mas dá para refazer" como variante do produto.
   - O que não está claro: o comando em si (nível técnico) precisa ser tecnicamente IMPOSSÍVEL de
     rodar 2x sobre o mesmo mês, ou só operacionalmente desencorajado (roda, mas ninguém aciona)?
     O precedente do Desempenho permite rerun de propósito (SNAP-06) — a Fase 137 precisa decidir
     se o comando trava (erro se já existe snapshot) ou se simplesmente não tem UI/agendamento que
     o chame duas vezes.
   - Recomendação: travar no código (recusar se já existe row com aquela competência, a menos que
     uma flag `--forcar` explícita seja passada só para operação manual excepcional) — mais seguro
     dado D-12, e mais fácil de relaxar depois (fase de "reabrir competência", já cogitada em
     `<deferred>`) do que apertar depois que o hábito de rerun já existir.

2. **A "exceção por empresa" de D-01 é all-or-nothing ou pode ser parcial?**
   - O que sabemos: "empresa com tabela fora do padrão ganha uma exceção própria".
   - O que não está claro: se uma empresa tem 4 faixas cadastradas mas a 5ª não foi cadastrada, ela
     cai para "sem faixa" (ausência visível, D-04 nota "cobrir tabelas antigas/fora do padrão") ou
     herda a faixa que falta do serviço? A modelagem de duas tabelas separadas (§5) sugere
     all-or-nothing por simplicidade (tem QUALQUER linha na tabela de empresa → usa só a tabela de
     empresa, ignora a do serviço inteiramente) — mas não foi confirmado com o usuário.

## Sources

### Primárias (HIGH confidence — leitura direta de código nesta sessão)
- `app/Http/Controllers/AdminController.php` (linhas 1-900) — tela `/financeiro`, `FAIXAS`,
  `calcularFaixa()`, agregação por `parent_company_id`
- `app/Support/CobrancaCalculator.php` — cálculo de cobrança a partir de faixa
- `app/Models/CompanyMonthlyRevenue.php`, `app/Services/AdmanService.php:1190-1220`
  (`syncMonthRevenue`)
- `app/Models/ShopeeMetric.php`, `app/Services/Metrics/ShopeeMetricDiffService.php`,
  `app/Services/Metrics/MetricDiffDispatcher.php`, `app/Services/Metrics/MetricPeriodResolver.php`
- `app/Models/FechamentoRecebido.php` +
  `database/migrations/2026_05_19_200001_create_fechamento_recebidos_table.php`
- `app/Jobs/EnviarRelatorioFechamentoJob.php`, `app/Mail/RelatorioFechamentoMail.php`
- `app/Models/CompanyGroup.php`, `app/Http/Controllers/CompanyGroupController.php`
- `app/Console/Commands/ConsolidarMesDesempenho.php`,
  `app/Services/Desempenho/CompanyScoreSnapshotWriter.php`,
  `app/Console/Commands/VerificarConsolidacaoDesempenho.php`,
  `app/Models/DesempenhoScoreSnapshot.php`,
  `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php`
- `app/Models/BonusFaixa.php`, `app/Http/Controllers/DesempenhoConfigController.php`,
  `database/migrations/2026_07_09_140002_create_bonus_faixas_table.php`,
  `database/migrations/2026_07_09_140003_seed_bonus_faixas_iniciais.php`
- `app/Models/Servico.php`, `app/Models/ContratoServico.php`
- `app/Services/Portfolio/CarteiraContextService.php:247-276`
- `app/Console/Commands/SyncFaturamentoMensal.php`, `app/Jobs/SyncFaturamentoMensalJob.php`,
  `routes/console.php` (linhas 15, 96-106, 260-320)
- `database/migrations/2026_06_11_120000_create_company_groups_table.php`
- `.planning/phases/137-.../137-CONTEXT.md` (decisões travadas D-01..D-12, medições de 46
  empresas/15 grupos e 5 empresas/2 pais — não re-medidas nesta sessão por indisponibilidade do
  MariaDB local)
- `.planning/learnings/desempenho-bonificacao.md` (armadilhas §0.02, §1, §4, §6, §10.1)

### Não verificadas nesta sessão
- Contagem viva de empresas com receita simultânea ML+Shopee (MariaDB local não estava rodando —
  `Connection refused, Host 127.0.0.1, Port 3306`). As contagens de grupo (46 empresas/15 grupos)
  vêm do CONTEXT.md, já medidas por essa sessão anterior — tratadas aqui como CITED, não
  re-verificadas.

## Metadata

**Confidence breakdown:**
- Rollup Shopee/ML (Q1): HIGH — solução aponta para código já existente e testado em produção
- Base do snapshot congelado (Q2): HIGH — leitura direta de model+migration, conclusão é lógica
  sobre schema medido
- Precedente de consolidação (Q3): HIGH — arquivo lido quase por inteiro, padrão bem documentado no
  próprio código-fonte
- Impacto nos consumidores (Q4): HIGH — 4 call-sites localizados e lidos
- Modelagem das faixas (Q5): MEDIUM — decisão de schema é recomendação (Claude's Discretion do
  CONTEXT), fundamentada em precedente forte mas não confirmada com o usuário
- Pitfalls de migration: HIGH — todos com precedente de incidente real documentado em
  `.planning/learnings/`

**Data da pesquisa:** 2026-09-02
**Válida até:** ~2026-10-02 (30 dias — módulo estável, mas Adman/Shopee têm histórico de mudanças
de comportamento que já pegaram o projeto de surpresa antes; reverificar `syncMonthRevenue` e
`ShopeeMetricDiffService` se algum dos dois for tocado por outra fase antes desta ser executada)
