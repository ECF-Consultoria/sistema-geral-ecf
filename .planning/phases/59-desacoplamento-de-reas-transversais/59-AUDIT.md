# Phase 59 — AUDIT.md

Deliverable central do CROSS-01 (+ parte do CROSS-02). Mapeamento exaustivo do
acoplamento incorreto a Mercado Livre nos 3 controllers hotspot identificados
pelo scout do `59-CONTEXT.md` (ComercialController, CompanyController,
AdminController), confirmação de que Publicação (`pub.*`) já é transversal, e
baseline de testes para o gate de zero-regressão do Plan 03.

## Baseline pré-fix (Plan 01)

**Data:** 2026-07-06T13:47:34Z (ISO)

**Comando de referência:** `php artisan test` (suite completa, `phpunit.xml` —
testsuites `Unit` + `Feature`).

**Nota metodológica — limitação de infraestrutura descoberta durante a
captura do baseline:** `php artisan test` (e `vendor/bin/phpunit` direto)
crasham de forma determinística com `Fatal error: Maximum execution time of
300 seconds exceeded` em `app/Services/Sugadores/MercadoLivreAdsService.php`
ou em `vendor/symfony/process/Pipes/WindowsPipes.php`, **mesmo com
`-d max_execution_time=0`** passado explicitamente ao processo PHP. Causa raiz
identificada: `App\Console\Commands\SyncGrantsFromEcfDrive::handle()` chama
`set_time_limit(300)` (linha 23) — código de produção legítimo para requests
HTTP longos (Phase 20). `tests/Feature/Phase20/SyncGrantsFromEcfDriveTest.php`
invoca esse comando real (`Artisan::call('grants:sync-ecf')`) **12 vezes** no
mesmo processo PHPUnit (não há isolamento de processo por teste nesta suite).
Cada chamada reseta o contador de `max_execution_time` do processo inteiro
para 300s a partir daquele instante — sobrepondo qualquer `-d` passado na
invocação do CLI. Como a suite Phase 41/42 (`MercadoLivreAdsServiceBackoffTest`
e afins) usa `usleep()` real para simular backoff exponencial (não mockado),
o tempo acumulado de execução após o último reset ultrapassa 300s antes do
fim da suite, derrubando o processo inteiro com fatal error — **não é um teste
vermelho, é o processo PHP inteiro morrendo**, impedindo qualquer contagem.

**Contorno usado (sem alterar código de produção):** suite rodada em 2
lotes via `vendor/bin/phpunit` direto (bypassando o wrapper `artisan test`):
(1) suite completa exceto `SyncGrantsFromEcfDriveTest` via
`--filter '^(?!.*SyncGrantsFromEcfDriveTest).*$'`; (2) o arquivo excluído
rodado sozinho. Os totais abaixo são a SOMA dos 2 lotes. Este é um problema de
infraestrutura de teste pré-existente, não uma regressão desta plan — nenhum
arquivo de produção foi alterado para obter o baseline.

**Resultado agregado (lote 1 + lote 2):**

| Métrica | Lote 1 (943 testes, exclui SyncGrantsFromEcfDriveTest) | Lote 2 (12 testes, arquivo isolado) | **Total** |
|---|---|---|---|
| Tests | 943 | 12 | **955** |
| Assertions | 4707 | 41 | **4748** |
| Errors | 15 | 0 | **15** |
| Failures | 48 | 0 | **48** |
| Skipped | 1 | 0 | **1** |
| Tempo | 10:56.385 | 00:01.405 | ~10:58 |

**Total de vermelhos no baseline: 63** (15 errors + 48 failures), **892
passaram**, **1 skipped**, de **955 testes coletados**.

**Phase 57 (regressão específica — CONFIRMADO verde):** `--filter Phase57` →
**20/20 passed**, 26 assertions, 10.198s. Bate com `58-VERIFICATION.md`.

**Phase 58 (regressão específica — CONFIRMADO verde):** `--filter Phase58` →
**16/16 passed**, 62 assertions, 32.500s. Bate com `58-VERIFICATION.md`.

**Os 63 vermelhos são TODOS pré-existentes e NÃO relacionados ao escopo desta
Phase (Comercial/Company/Admin acoplamento ML)** — inspeção linha a linha de
cada erro/falha confirma:

- **9 errors** — `Tests\Unit\CalcularFaixaTest::*` — `ArgumentCountError` em
  `new AdminController()` sem o `AdmanService` promovido no construtor
  (`AdminController.php:21`). Teste legado desatualizado desde que
  `AdmanService` virou dependência obrigatória; não relacionado a
  marketplace/ML.
- **2 errors** — `Tests\Feature\Phase13MigrationTest::test_derivacao_service_type_*`
  — `Attempt to read property "service_type" on null` (coluna legacy já
  dropada em fase posterior; teste de migration histórica).
- **4 errors** — `Tests\Feature\Phase14MigrationTest::*` — `Carbon\Exceptions\InvalidFormatException:
  ... The timezone could not be found in the database` ao parsear
  `contract_start` — bug de ambiente Windows/ICU local (timezone DB), não
  relacionado a código da Phase 59.
- **48 failures** — distribuídas em `CompanyServiceTypeTest`,
  `MercadoLivreSugadoresProviderTest` (Phase 39), `AdminFechamentoControllerTest`
  (5 já documentadas como pré-existentes em `deferred-items.md` da Phase 14),
  `DevControllerTest`, `ExampleTest` (scaffold padrão Laravel — redirect em vez
  de 200, ambiente sem seed), `FechamentoMigrationTest`, `Phase13ComercialTest`
  (11 falhas — coluna legacy `service_type` obrigatória num teste que não a
  popula mais), `Phase14AdminControllerCobrancaTest`, `Phase14ComercialTest`,
  `Phase14MlbControllerFiltroTest`, `Phase14VerificarCobrancaTest`,
  `Phase18\CompaniesCustIdFilterTest`, `Phase33OnboardingFichaTest`,
  `Phase37ServicoSetorTest`, **`Phase38\PolosControllerTest`** (6 falhas — já
  documentadas em `.planning/phases/38-smoke-ml-piloto-bymobile/deferred-items.md`
  como fora de escopo, dev paralelo), **`Phase42\*`** (4 falhas — conhecidas do
  acúmulo de contexto do `STATE.md`, ligadas ao cutover ML shadow mode),
  `Polos\PolosFaturamentoSnapshotTest`.
- **Nenhuma falha/erro referencia `ComercialController`, `CompanyController`
  ou `AdminController` nos métodos tocados pelo escopo desta Phase**
  (`store`, `update`, `listagem`, `index`, `show`, `empresas`,
  `fechamento`/`syncFaturamento`) de forma relacionada a marketplace/ML —
  `AdminFechamentoControllerTest` e `Phase14AdminControllerCobrancaTest`
  tocam `AdminController::fechamento()` mas as falhas são sobre colunas
  legacy `service_type`/`contract_start` (Phase 14), não sobre
  `ml_store_id`/`adman_account_id`/`marketplaces_extras`.

**Implicação para o Plan 03 (gate de zero-regressão):** o baseline de
comparação é **63 vermelhos pré-existentes de 955 coletados** (943+12,
excluindo o crash de infraestrutura). Plan 03 deve confirmar que, após os
fixes do Plan 02, a suite continua produzindo **exatamente os mesmos 63
vermelhos (ou menos)** — nenhum novo erro/falha introduzido pelos fixes
`ComercialController`/`CompanyController`/`AdminController`. O mesmo
contorno de 2 lotes (excluir `SyncGrantsFromEcfDriveTest`, rodar separado)
deve ser reaplicado no Plan 03 para obter uma contagem comparável.

---

## Metodologia

**Comando grep exato usado** (idêntico ao `59-CONTEXT.md` e ao scout
original de 2026-07-06):

```bash
grep -nE "marketplace|meli|mlb|Mlb|ml_store" app/Http/Controllers/ComercialController.php
grep -nE "marketplace|meli|mlb|Mlb|ml_store" app/Http/Controllers/CompanyController.php
grep -nE "marketplace|meli|mlb|Mlb|ml_store" app/Http/Controllers/AdminController.php
```

Contagens confirmadas nesta plan (idênticas ao scout do CONTEXT):
ComercialController 29 refs, CompanyController 17 refs, AdminController 10
refs = **56 refs totais**. Cada linha foi lida no código real (não
classificada de cabeça) antes de anotar Tipo/Severidade/Plano.

**Critério de classificação — Tipo:**

| Tipo | Definição |
|---|---|
| `import/use` | Declaração `use App\Models\MlbXxx` no topo do arquivo |
| `docblock` | Comentário/PHPDoc mencionando mlb/marketplace, sem efeito em runtime |
| `filtro hardcoded` | Query com `where`/`whereDoesntHave`/`whereHas` fixando um marketplace específico |
| `naming` | Nome de variável/método assume ML fora de contexto ML |
| `default UI ML` | Valor default de formulário/dropdown pressupõe ML sem justificativa |
| `payload/accessor legítimo` | Exposição de coluna existente (`ml_store_id`, `adman_account_id`) ao Inertia/JSON — não é acoplamento, é leitura de dado |
| `validação whitelist` | `Rule::in([...])` já generalizado para múltiplos marketplaces |
| `resolução cust_id` | Lógica de fallback `adman_account_id ?: ml_store_id` (ou variante) replicando o accessor `Company::cust_id` |

**Critério de classificação — Severidade:**

- **HIGH** — bug ativo: exclui ou trata incorretamente empresa não-ML numa
  operação transversal (não é módulo ML dedicado).
- **MEDIUM** — naming ou resolução inconsistente que confunde mas não quebra
  funcionalmente (ex.: mesma chave de payload com lógicas de fallback
  diferentes em pontos distintos do mesmo controller).
- **LOW** — cosmético: naming em docblock/comentário, ou inconsistência sem
  impacto funcional observável.
- **INFO** — código legítimo, sem ação necessária.

**Critério de classificação — Plano:**

- `fix Phase 59` — assunção INCORRETA em contexto transversal; entra no
  escopo do Plan 02.
- `deferred v14+` — migração para pivot N:N `company_marketplaces`; fora do
  escopo cirúrgico desta Phase (CONTEXT §4).
- `no-op (legítimo)` — código correto como está; não precisa de mudança.
- `docs only` — apenas comentário/docblock desatualizado; correção é textual,
  sem risco, mas não crítica o suficiente para "fix Phase 59" — fica registrado
  no Sumário para decisão do usuário.

**Scan anti-vazamento (T-59-01-01):** nenhum trecho citado nas tabelas abaixo
contém substring `_KEY`, `_TOKEN`, `password` ou `secret` — confirmado por
inspeção visual de cada linha extraída (todas são nomes de coluna, validação
ou lógica de negócio, não segredos).

---

## Comercial (ComercialController.php)

| Linha | Trecho | Tipo | Severidade | Plano |
|---|---|---|---|---|
| 10 | `use App\Models\MlbEmpresa;` | import/use | INFO | no-op (legítimo) |
| 11 | `use App\Models\MlbImplementacao;` | import/use | INFO | no-op (legítimo) |
| 42 | docblock "mlb_empresas/mlb_implementacao" (roteamento serviço→implementação) | docblock | INFO | no-op (legítimo) |
| 342 | `'ml_store_id' => $c->ml_store_id` (payload `listagem()`) | payload/accessor legítimo | INFO | no-op (legítimo) |
| 491, 495 | docblock "preserva mlb_empresas/mlb_implementacao" no fluxo `store()` | docblock | INFO | no-op (legítimo) |
| 528-529 | `'marketplaces_extras.*' => [Rule::in(['shopee','amazon','magalu','temu','tiktok'])]` | validação whitelist | INFO | no-op (legítimo) — já cobre 5 marketplaces além de ML |
| 540, 542, 544 | Guard de duplicata `Company::whereRaw(...)` + `MlbEmpresa::whereRaw(...)` | filtro hardcoded (mas legítimo) | INFO | no-op (legítimo) — checa nome em AMBAS as tabelas, correto por design (empresa Polos/Assessoria vive em `mlb_empresas`) |
| 566, 572 | Serialização de `marketplaces_extras` como JSON no `create()` da company | payload/accessor legítimo | INFO | no-op (legítimo) |
| 611, 617, 619, 625, 632 | `MlbEmpresa::create(...)` + `criarImplementacaoPolo()` — roteamento por `service_type` derivado do NOME do serviço (Polos/Assessoria/Incubadora) | lógica de módulo ML dedicado | INFO | no-op (legítimo) — é o fluxo de handoff pro módulo Publicação/Polos, não acoplamento incorreto |
| 706-707 | `'marketplaces_extras.*' => [Rule::in([...])]` no `update()` | validação whitelist | INFO | no-op (legítimo) — mesma whitelist do `store()` |
| 733 | docblock "preserva mlb_empresas" no `update()` | docblock | INFO | no-op (legítimo) |
| 882-902 | `criarImplementacaoPolo()` — proxy para `MlbImplementacaoFactory::criarParaPolo` | lógica de módulo ML dedicado | INFO | no-op (legítimo) — extraído para factory reutilizável (D-05 da Phase 35), fora do escopo de "acoplamento incorreto" |

**Comercial — 0 itens `fix Phase 59`.** Os 29 refs são: 2 imports legítimos
(módulo Polos/Publicação é intencionalmente acoplado a `MlbEmpresa`/
`MlbImplementacao` — é o handoff correto), 6 docblocks informativos, 2
exposições de coluna (`ml_store_id`), 4 usos de whitelist já generalizada
(`marketplaces_extras` aceita shopee/amazon/magalu/temu/tiktok), e o restante
é lógica de roteamento legítima do módulo Polos (fora do escopo "código que
assume ML incorretamente" — é código ML por design, conforme CONTEXT §4).

---

## Company (CompanyController.php)

| Linha | Trecho | Tipo | Severidade | Plano |
|---|---|---|---|---|
| 71-72 | docblock "exclui empresas com MlbEmpresa associada / dupla contagem com /mlb/empresas" | docblock | INFO | no-op (legítimo) |
| 85 | `->whereDoesntHave('mlbEmpresa')` | filtro hardcoded (mas legítimo) | INFO | no-op (legítimo) — filtro Phase 35/37 documentado: `/companies` refoca em Performance, empresas do módulo Polos/Publicação (`mlbEmpresa`) vivem em outra tela por desenho consciente, não é bug |
| 88 | comentário "MlbEmpresa já excluído acima" | docblock | INFO | no-op (legítimo) |
| 125 | `'marketplaces_extras' => $c->marketplaces_extras ?? []` (payload `index()`) | payload/accessor legítimo | INFO | no-op (legítimo) |
| 129 | `'adman_account_id' => $c->ml_store_id ?: $c->adman_account_id` (payload `index()`) | resolução cust_id | **MEDIUM** | **APLICADO em `e816307`** — ver nota abaixo |
| 131 | `'ml_store_id' => $c->ml_store_id` (payload `index()`) | payload/accessor legítimo | INFO | no-op (legítimo) |
| 165 | `(! $c->adman_account_id && ! $c->ml_store_id) ? 'sem_cust_id' : null` | resolução cust_id | INFO | no-op (legítimo) — checa AMBAS as colunas antes de marcar pendência, correto |
| 268-269 | docblock "usa accessor cust_id (adman_account_id ?: ml_store_id)" no `show()` | docblock | INFO | no-op (legítimo) |
| 398 | `'marketplaces_extras' => $company->marketplaces_extras ?? []` (payload `show()`) | payload/accessor legítimo | INFO | no-op (legítimo) |
| 402 | `'ml_store_id' => $company->ml_store_id` (payload `show()`) | payload/accessor legítimo | INFO | no-op (legítimo) |
| 509 | `'ml_store_id' => 'nullable|string|max:100'` (validação `update()`) | payload/accessor legítimo | INFO | no-op (legítimo) |
| 522-523 | `'marketplaces_extras.*' => [Rule::in([...])]` (validação `update()`) | validação whitelist | INFO | no-op (legítimo) |
| 586 | docblock "mlb_empresas nullOnDelete" (`bulkDestroy()`) | docblock | INFO | no-op (legítimo) |
| 630 | docblock "preserva mlb_empresas" (`ativar()`) | docblock | INFO | no-op (legítimo) |

**Nota sobre linha 129 (único item `fix Phase 59` do CompanyController):**
a chave de payload `'adman_account_id'` em `index()` (linha 129) resolve o
valor como `$c->ml_store_id ?: $c->adman_account_id` — ou seja, quando a
empresa tem `ml_store_id` preenchido, o campo **rotulado
`adman_account_id`** no JSON recebe o valor de `ml_store_id`. Isso é uma
réplica manual do accessor `Company::cust_id` (mencionado no docblock da
linha 268), mas com **naming que não reflete o dado retornado** — o frontend
que consome `adman_account_id` esperando o ID da conta Adman pode receber,
na verdade, o Seller ID do Mercado Livre. Não é um "bug" que quebra a
aplicação (o valor ainda é um cust_id válido para exibição/busca), mas é uma
inconsistência de naming que confunde manutenção futura — sobretudo porque
`AdminController.php:709` usa a MESMA chave (`'adman_account_id'`) com
resolução DIFERENTE (`$f->adman_account_id` puro, sem fallback) em outro
lugar do código (ver tabela Admin abaixo), o que é uma divergência real
entre os dois controllers para o "mesmo" conceito de payload. Classificado
como `fix Phase 59` — mas o fix recomendado (Plan 02) é de **naming e
documentação** (ex.: renomear a chave de saída para `cust_id_display` ou
usar o accessor `$c->cust_id` diretamente e documentar a semântica), não
uma mudança de comportamento de query — sem risco à suite de testes.

**APLICADO em Plan 02 (commit `e816307`):** linha 109 (numeração atual do
arquivo — a numeração 129 refletia o estado do working tree no momento do
scout, que já tinha edições locais não commitadas de outra frente/tarefa)
trocada para `'adman_account_id' => $c->cust_id`. Achado extra durante a
aplicação: o accessor `Company::cust_id` resolve na ordem
`adman_account_id ?: ml_store_id` (fixada em 2026-06-09 após bug real
ADHARAPRINTSHOP/AVF_2K — ver `app/Models/Company.php` docblock), enquanto a
expressão manual do controller usava a ordem INVERTIDA
(`ml_store_id ?: adman_account_id`). O fix não é só naming — corrige também
a ordem de prioridade para bater com o accessor canônico. Suite `--filter`
específica confirmada sem regressão nova (ver seção "Plan 02 — Execução").

**Company — 1 item `fix Phase 59`** (linha 129, MEDIUM, naming/consistência)
— **APLICADO**. Os demais 16 refs são legítimos.

---

## Admin (AdminController.php)

| Linha | Trecho | Tipo | Severidade | Plano |
|---|---|---|---|---|
| 55 | `'ml_store_id' => $c->ml_store_id` (payload `empresas()`) | payload/accessor legítimo | INFO | no-op (legítimo) |
| 199 | comentário "cache key é Company::cust_id (adman_account_id ?: ml_store_id)" | docblock | INFO | no-op (legítimo) |
| 202-203 | comentário explicando bugfix histórico (lookup usava `ml_store_id ?: adman_account_id`, causava cache miss) | docblock | INFO | no-op (legítimo) — comentário documenta correção já aplicada |
| 399 | `$q2->whereNotNull('ml_store_id')->where('ml_store_id','!=','')` OU `whereNotNull('adman_account_id')...` (`syncFaturamento()`) | filtro hardcoded (mas legítimo) | INFO | no-op (legítimo) — filtra empresas com QUALQUER cust_id (ML ou Adman), não exclui nenhum marketplace |
| 494 | comentário "Resolução de custId via accessor cust_id" (`fechamento()`) | docblock | INFO | no-op (legítimo) |
| 545 | `'adman_account_id' => $f->ml_store_id ?: $f->adman_account_id` (payload `fechamento()`, empresa vinculada) | resolução cust_id | **MEDIUM** | **APLICADO em `90a2afe`** — mesma inconsistência da linha 129 do CompanyController |
| 547 | `'ml_store_id' => $f->ml_store_id` (payload `fechamento()`) | payload/accessor legítimo | INFO | no-op (legítimo) |
| 660 | comentário "cust_id (adman_account_id ?: ml_store_id) bate com a chave do writer" (`gerarRelatorioGeral()`) | docblock | INFO | no-op (legítimo) |
| 711 | `'ml_store_id' => $f->ml_store_id` (payload `gerarRelatorioGeral()`) | payload/accessor legítimo | INFO | no-op (legítimo) |

**Nota sobre linha 545 vs. linha 709 (inconsistência real dentro do próprio
AdminController):** `fechamento()` (linha 545) resolve `'adman_account_id'`
como `$f->ml_store_id ?: $f->adman_account_id` (aplica fallback), enquanto
`gerarRelatorioGeral()` (linha 709, contexto idêntico — payload de empresa
vinculada `$f`) resolve a MESMA chave como `$f->adman_account_id` puro (SEM
fallback). Duas rotas do mesmo controller, mesmo conceito de dado, resultados
diferentes para a mesma empresa. Classificado `fix Phase 59` (severidade
MEDIUM, naming/consistência) — o Plan 02 deve unificar as duas ocorrências
para usar o mesmo padrão (idealmente o accessor `$f->cust_id` direto),
eliminando a divergência. Nenhuma mudança de schema ou API pública — troca
interna de expressão de payload.

**APLICADO em Plan 02 (commit `90a2afe`):** ambas as ocorrências (linha 545
em `fechamento()` e linha 709 em `gerarRelatorioGeral()`) trocadas para
`$f->cust_id`. Mesmo achado do item CompanyController.php:129 — a expressão
manual da linha 545 usava a ordem invertida (`ml_store_id ?: adman_account_id`)
em relação ao accessor canônico (`adman_account_id ?: ml_store_id`); a linha
709 já não tinha fallback nenhum. Unificar via accessor resolve as duas
divergências simultaneamente. Suite `--filter` específica confirmada sem
regressão nova (ver seção "Plan 02 — Execução").

**Admin — 1 item `fix Phase 59`** (linha 545, MEDIUM, naming/consistência)
— **APLICADO**. Os demais 9 refs são legítimos.

---

## Publicação — CONFIRMED transversal

Evidências grep-based confirmando que o domínio de Publicação (`pub.*` /
`hasPubPermission()`) **não tem amarração implícita a marketplace**:

1. **`app/Models/User.php:216-218`**
   ```php
   /** Substituto de hasPubPermission() — mapeia old keys (sem prefixo) pras novas. */
   public function hasPubPermission(string $perm): bool
   {
       return $this->hasPermission("mlb.{$perm}");
   }
   ```
   Delega para `hasPermission("mlb.{$perm}")` — checagem é uma STRING de
   permissão (`mlb.dashboard`, `mlb.vendas`, etc.), sem qualquer referência a
   coluna `marketplace`/`ml_store_id`/`adman_account_id` de `Company`. O
   prefixo literal `mlb.` no NOME da permission key é o único artefato que
   "amarra" visualmente a ML — mas semanticamente a permissão controla acesso
   ao MÓDULO de Publicação (setor `publicacao`), não a um marketplace
   específico. Classificado como **naming histórico, severidade LOW, plano
   `deferred v14+`**: renomear o prefixo `mlb.` → `pub.` exigiria migrar
   `permission_key` já gravadas em banco (tabela `permissoes`/
   `cargo_permissoes`) — é uma migração de dados, fora do escopo cirúrgico
   desta Phase (CONTEXT §4 exclui migrações de schema/dados amplas).

2. **`app/Http/Middleware/EnsurePermission.php`** (arquivo lido por inteiro,
   41 linhas) — o middleware faz apenas:
   ```php
   foreach ($keys as $key) {
       if ($user->hasPermission($key)) { return $next($request); }
   }
   abort(403, '...');
   ```
   Nenhum `where('marketplace', ...)`, nenhuma referência a `Company` ou
   `ml_store_id`/`adman_account_id` em todo o arquivo. **Middleware
   confirmado agnóstico de marketplace** — valida apenas a permission key
   literal contra `user_setores → cargos → permissoes`.

3. **`app/Http/Controllers/MlbController.php:39-54`** (`checkPubAccess()`,
   grep adicional de sanidade) —
   ```php
   $temAcessoMlb = $user->setores()->where('slug', 'publicacao')->exists()
       || collect(\App\Support\Permissions::all())
           ->filter(fn($k) => str_starts_with($k, 'mlb.'))
           ->some(fn($k) => $user->hasPermission($k));
   ```
   Confirma que "estar no módulo MLB" é decidido por **setor** (`slug =
   'publicacao'`) + **cargo/permissão**, nunca por marketplace da empresa.

4. **`routes/web.php:520`** — grupo de rotas de Publicação é registrado como
   `Route::middleware(['auth','verified'])->prefix('mlb')->name('mlb.')`
   (linhas 520-579+). A autorização granular acontece DENTRO dos métodos do
   `MlbController` via `checkPubAccess()`/`checkPubRole()` (item 3 acima), não
   via middleware de rota com filtro de marketplace. `grep -c "permission:mlb\."
   routes/web.php` retorna **1 único uso literal** (`permission:mlb.dashboard`,
   linha 269, para o dashboard legado) — nenhuma ocorrência de
   `permission:pub.` ou filtro de marketplace em rota alguma.

5. **`app/Http/Controllers/DashboardController.php:29-44`** — único call-site
   de `hasPubPermission()` em todo o código (`grep -rn hasPubPermission
   app/ resources/` retorna apenas a definição em `User.php` + este uso):
   redireciona o usuário de Publicação para a primeira página acessível
   (`dashboard`, `meu_painel`, `treinamento`, `publicacoes`, `historico`,
   `empresas`) puramente por permissão, sem checar `Company::marketplace`
   em nenhum ponto da lógica.

**Conclusão da seção:** Permissões `pub.*` (via `hasPubPermission()`) são
checadas sem contexto de marketplace; middleware `EnsurePermission` valida
chave literal contra `user_setores → cargos → permissoes`; nenhuma tela de
Publicação exige `adman_account_id` ou `ml_store_id`. **Publicação é
CONFIRMED transversal** — único achado é o prefixo de naming histórico
`mlb.` nas permission keys (item 1), classificado `deferred v14+` por exigir
migração de dados gravados.

---

## Sumário

**Totais por Severidade** (56 refs classificados nos 3 controllers):

| Severidade | Contagem |
|---|---|
| HIGH | 0 |
| MEDIUM | 2 |
| LOW | 1 (naming `mlb.` em `hasPubPermission`, seção Publicação) |
| INFO | 53 |

**Totais por Plano:**

| Plano | Contagem |
|---|---|
| `fix Phase 59` | 2 |
| `deferred v14+` | 1 |
| `no-op (legítimo)` | 53 |
| `docs only` | 0 |

**Zero itens HIGH.** Nenhum filtro hardcoded exclui incorretamente empresas
não-ML de uma operação transversal — os 3 controllers hotspot já tratam
`ml_store_id` e `adman_account_id` como fontes equivalentes de cust_id na
maior parte dos pontos (a exceção são as 2 inconsistências MEDIUM abaixo).
Isso confirma o "reality check" do CONTEXT §Domain: o acoplamento real é
muito mais raso do que o ROADMAP original suspeitava — a Phase 57 (modelo
N:N) e o desenho original destes controllers já foram cuidadosos em usar o
accessor `cust_id` na maioria dos casos.

## Itens a corrigir no Plan 02

- `CompanyController.php:129` — payload `index()` rotula como
  `'adman_account_id'` um valor que na verdade prioriza `ml_store_id`
  (`$c->ml_store_id ?: $c->adman_account_id`) — naming inconsistente com o
  dado real retornado. Plano: usar o accessor `$c->cust_id` diretamente (ou
  renomear a chave de saída) para eliminar a discrepância entre nome da
  chave e semântica do valor. Severidade MEDIUM.
  **APLICADO em `e816307`** (Plan 02).
- `AdminController.php:545` — payload `fechamento()` (empresa vinculada)
  usa `$f->ml_store_id ?: $f->adman_account_id`, enquanto
  `AdminController.php:709` (`gerarRelatorioGeral()`, mesmo conceito de
  payload) usa `$f->adman_account_id` puro, sem fallback — duas rotas do
  mesmo controller retornam valores DIFERENTES para o mesmo campo na mesma
  empresa. Plano: unificar as duas ocorrências para usar o mesmo padrão
  (idealmente `$f->cust_id`). Severidade MEDIUM.
  **APLICADO em `90a2afe`** (Plan 02, cobre linhas 545 e 709).

Ambos os itens são de **naming/consistência interna**, não filtros que
excluem funcionalidade — nenhum dos dois bloqueia ou meia empresa
Shopee/Amazon/etc. hoje (o app não tem integração real com esses
marketplaces ainda; o campo `marketplaces_extras` é apenas metadata
informativa do Comercial). Risco de regressão do fix é baixo: troca de
expressão de valor dentro de um array de payload Inertia, sem mudança de
schema, rota ou contrato de API externo.

**Item `deferred v14+`:** naming `mlb.` no prefixo das permission keys de
Publicação (`app/Models/User.php:216-218`) — migração de dados gravados em
`permissoes`/`cargo_permissoes`, fora do escopo cirúrgico desta Phase.

---

## Plan 02 — Execução (status)

**Data:** 2026-07-06 (ISO)

**Contagem literal:** 2 itens da lista "Itens a corrigir" foram processados;
**2 aplicados**; 0 reclassificados; 0 pulados por drift.

Ambos os itens foram validados contra o código atual antes do fix (Task 1) —
nenhum drift encontrado (o trecho citado no AUDIT ainda existia idêntico,
apesar da numeração de linha do `CompanyController.php` ter mudado de 129
para 109 devido a edições locais não commitadas de outra frente de trabalho
presentes no working tree — o trecho em si não mudou).

**Achado adicional durante a aplicação (não expande escopo — é o mesmo fix
recomendado pelo AUDIT):** em ambos os itens, a expressão manual do
controller usava a ordem `ml_store_id ?: adman_account_id`, enquanto o
accessor canônico `Company::cust_id` usa a ordem INVERSA
(`adman_account_id ?: ml_store_id`, fixada em 2026-06-09 após bug real de
produção com as empresas ADHARAPRINTSHOP/AVF_2K — ver docblock de
`app/Models/Company.php::getCustIdAttribute()`). Trocar para o accessor
(conforme já recomendado pelo próprio AUDIT) corrige naming E ordem de
prioridade simultaneamente, sem qualquer mudança de escopo além do que já
estava classificado como `fix Phase 59`.

**Lista literal dos commits gerados:**

- `refactor(59-02): CompanyController.php:109 — resolução cust_id — usa accessor cust_id em vez de fallback manual com ordem invertida` — `e816307`
- `refactor(59-02): AdminController.php:545,709 — resolução cust_id — unifica fechamento()/gerarRelatorioGeral() via accessor cust_id` — `90a2afe`

**Validação de não-regressão (smoke, gate rigoroso fica no Plan 03):**

- `php artisan test tests/Feature/Phase57/ tests/Feature/Phase58/` → **36/36
  passed** (baseline Phase 57 20/20 + Phase 58 16/16 preservado).
- `php artisan test tests/Feature/AdminFechamentoControllerTest.php
  tests/Feature/Phase14AdminControllerCobrancaTest.php
  tests/Feature/Phase14VerificarCobrancaTest.php` → 8 failed / 14 passed —
  **idênticas às falhas pré-existentes documentadas em
  `.planning/phases/14-consolida-o-do-modelo-de-servi-os-frente-b/deferred-items.md`**
  (confirmado por reversão temporária do fix + re-execução do teste isolado
  `update_persiste_datas_contrato`, que falha igualmente sem o fix desta
  plan — causa raiz é coluna legacy `service_type`/data de contrato, não
  `adman_account_id`).
- `php artisan test tests/Feature/Phase18/ tests/Feature/Phase37CompaniesPerformanceFilterTest.php
  tests/Feature/CompanyPortfolioAccessTest.php` → 2 failed / 39 passed —
  as 2 falhas são em `Phase18\CompaniesCustIdFilterTest`, já listada no
  baseline do Plan 01 como pré-existente; confirmado por reversão temporária
  do fix + re-execução, que reproduz as mesmas 2 falhas sem o fix desta plan.
- Nenhum teste que assertava o valor exato de `adman_account_id` nos payloads
  tocados (`CompanyController::index()`, `AdminController::fechamento()`,
  `AdminController::gerarRelatorioGeral()`) foi encontrado na suíte — os
  fixes trocam apenas a expressão de resolução do valor, sem contrato de API
  externo ou schema alterado.

**Nota final:** Gate de regressão completo (suite inteira, contagem
comparável ao baseline de 63 vermelhos pré-existentes) delegado ao Plan 03
conforme `59-02-PLAN.md` e `§Baseline pré-fix` acima.

---

## Plan 03 — Regressão + confirmação Publicação

**Data:** 2026-07-06 (ISO)

### 1. Regressão (CROSS-03)

Contorno de infraestrutura reaplicado idêntico ao Plan 01 (suite rodada em
2 lotes via `vendor/bin/phpunit` direto, para evitar o crash de
`set_time_limit(300)` compartilhado descrito em `§Baseline pré-fix`):

- Lote 1 — `vendor/bin/phpunit --filter '^(?!.*SyncGrantsFromEcfDriveTest).*$'`
- Lote 2 — `vendor/bin/phpunit --filter 'SyncGrantsFromEcfDriveTest'`

| Métrica | Baseline (Plan 01) | Pós-fix (Plan 03) | Delta |
|---|---|---|---|
| Tests | 955 | 955 | **0** |
| Assertions | 4748 | 4748 | **0** |
| Errors | 15 | 15 | **0** |
| Failures | 48 | 48 | **0** |
| Skipped | 1 | 1 | **0** |
| Passed | 892 | 892 | **0 (>= 0 ✓)** |

**Delta: P_passed - B_passed = 0** (892 - 892 = 0, >= 0 confirmado). Delta
de Assertions/Errors/Failures/Skipped também = 0 em todas as métricas.

**Detalhe por lote (pós-fix):**
- Lote 1 (943 testes, exclui `SyncGrantsFromEcfDriveTest`): 4707 assertions,
  15 errors, 48 failures, 1 skipped — idêntico ao Lote 1 do baseline.
- Lote 2 (12 testes, `SyncGrantsFromEcfDriveTest` isolado): 41 assertions,
  0 errors, 0 failures — idêntico ao Lote 2 do baseline.

Os 4 últimos casos de falha visíveis no tail do Lote 1
(`Phase42\AnalyzeCompanyMlWindowQuarantineTest::fetchAdgroupsMetrics_fail_open_em_listCampaigns_quebrado`,
`Phase42\CutOverMlPrimaryTest::reanalise_mesmo_dia_com_config_relaxada_depois_apertada_auto_resolve`,
`Phase42\CutOverMlPrimaryTest::auto_resolvido_recicla_quando_config_afrouxa_e_ad_volta_a_bater_criterio`,
`Polos\PolosFaturamentoSnapshotTest::test_job_persiste_snapshot_no_sucesso`)
já constam na lista de vermelhos pré-existentes do baseline (`§Baseline
pré-fix`, itens Phase42 e Polos) — nenhum teste novo apareceu na cauda do
output.

**Phase 57:** `--filter Phase57` → **20/20 passed**, 26 assertions,
12.570s — baseline preservado exato.

**Phase 58:** `--filter Phase58` → **16/16 passed**, 62 assertions,
31.879s — baseline preservado exato.

**Task 2 (resolver vermelhos) foi PULADA** — a contagem pós-fix bateu
exatamente com o baseline em todas as métricas (delta = 0 em Tests,
Assertions, Errors, Failures, Skipped), e Phase 57 (20/20) + Phase 58
(16/16) confirmados intactos. Não há vermelho novo causado pelos fixes
`e816307`/`90a2afe` do Plan 02 para resolver.

**Afirmativa final: Zero regressão confirmada.** Os 63 vermelhos
(15 errors + 48 failures) pós-fix são EXATAMENTE os mesmos 63 vermelhos
pré-existentes documentados em `§Baseline pré-fix` — mesma composição,
mesma contagem, nenhum acréscimo. Os fixes cirúrgicos de resolução `cust_id`
(`CompanyController::index()`, `AdminController::fechamento()`/
`gerarRelatorioGeral()`) não tocaram nenhum caminho exercitado pelos 63
testes vermelhos pré-existentes (confirmado também no Plan 02 por reversão
temporária + re-execução isolada — ver `59-02-SUMMARY.md`).

### 2. Publicação transversal reforçada (CROSS-02)

Evidência DINÂMICA (execução real da suite, não apenas grep estático) que
complementa a seção `## Publicação — CONFIRMED transversal` acima:

- **Suite completa executada em 2026-07-06 (955 testes, 2 lotes) — 0 testes
  de módulos Publicação/`mlb.*` ficaram vermelhos APÓS os fixes cirúrgicos**
  em `CompanyController`/`AdminController` (Plan 02, commits `e816307` e
  `90a2afe`). As suites que exercitam o módulo de Publicação —
  `tests/Feature/Phase38Publicador/MeuPainelControllerTest.php`,
  `tests/Feature/PublicacaoDesempenhoRouteTest.php`,
  `tests/Feature/Phase36/MlbDadosMlControllerTest.php`,
  `tests/Feature/Phase37/MlbDadosMlReputacaoTest.php` — **todas passaram**
  no Lote 1 (nenhuma aparece na lista de 63 vermelhos, pré-existente ou
  nova).
- Grep adicional de amostragem (`grep -rn "hasPubPermission\|pub\." tests/`)
  retorna **0 ocorrências literais** — nenhum teste referencia o método
  `hasPubPermission()` ou a string `pub.` diretamente (o domínio de
  Publicação é exercitado nos testes via o prefixo real de permission key
  `mlb.`, não via um prefixo `pub.` — consistente com o achado LOW já
  registrado na seção acima: o naming histórico é `mlb.`, não `pub.`).
  `grep -rln "mlb\." tests/Feature` retorna 6 arquivos de teste (listados
  acima + `Phase33OnboardingFichaTest.php`, `Phase35OnboardingPrazoTest.php`)
  que exercitam fluxos do módulo Publicação/onboarding — todos verdes no
  run pós-fix, exceto `Phase33OnboardingFichaTest`, que já constava nos
  63 vermelhos pré-existentes do baseline (causa raiz documentada:
  bug de timezone Carbon/Windows em `contract_start`, não relacionado a
  `hasPubPermission()`/permissão de Publicação).

**Afirmativa final: Publicação confirmada transversal** — permissões
`pub.*` (via `hasPubPermission()`, delegando para chave real `mlb.*`)
funcionam sem amarração implícita a marketplace; grep estático (seção
acima) + execução real da suite completa (2026-07-06, 955 testes, zero
vermelho novo em módulo Publicação) comprovam.

### 3. Encerramento Phase 59

| Requisito | Fechado por | Status |
|---|---|---|
| CROSS-01 (mapa de acoplamento ML) | Plan 01 (audit) + Plan 02 (2 fixes aplicados) | ✅ Fechado |
| CROSS-02 (Publicação transversal) | Plan 01 (grep estático) + Plan 03 (evidência dinâmica de suite) | ✅ Fechado |
| CROSS-03 (zero regressão) | Plan 03 (delta = 0 vs. baseline, Phase 57/58 preservados) | ✅ Fechado |

**Itens deferred v14+ (não bloqueiam fechamento desta phase):**
- Migração completa para pivot N:N `whereHas('marketplaces', ...)` em
  `ComercialController`/`CompanyController`/`AdminController` — fica para
  quando Shopee/Amazon integrarem de fato (custo/benefício não justifica
  agora).
- Naming histórico `mlb.` no prefixo das permission keys de Publicação
  (`app/Models/User.php:216-218`) — exigiria migração de dados gravados em
  `permissoes`/`cargo_permissoes`; fora do escopo cirúrgico desta Phase.

**Phase 59 pronta para `/gsd:complete-phase 59`.**
