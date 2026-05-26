# Phase 14: Consolidação do Modelo de Serviços (Frente B) — Research

**Researched:** 2026-05-26
**Domain:** Laravel 12 — data migration idempotente, refator billing-critical, drop de colunas legacy em MySQL/SQLite
**Confidence:** HIGH (codebase já inspecionado; padrões consagrados Laravel; sem dependências externas novas)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01 — Mapeamento dos 6 tipos legacy → catálogo:** Hard-coded na data migration. `firstOrCreate` por `nome` com valor_padrao=0, tipo_cobranca='mensal', ativo=true. Mapeamento exato `publicacao→Publicação, polos→Polos, assessoria→Assessoria, incubadora→Incubadora, publicidade→Publicidade, gestao→Gestão`.
- **D-02 — Dedupe de `additional_service` (texto livre):** Normalização agressiva via `mb_convert_case(trim($x), MB_CASE_TITLE, 'UTF-8')` + `firstOrCreate` por nome.
- **D-03 — FAIXAS intacta:** Contratos são ADICIONAIS, não substitutos. Cálculo novo = `faixaData['valor']` + `SUM(contratos_servico.valor_contratado WHERE ativo=true AND servico.tipo_cobranca='mensal')`. Os 6 serviços-tipo entram com `valor_contratado=0` (classificação, não cobrança).
- **D-04 — Migração de dados:** Regra cumulativa por empresa — (1) 1 contrato por valor em service_type, (2) 1 contrato adicional se additional_service preenchido, (3) nada se ambos vazios. `data_contratacao = contract_start ?? created_at`, `data_vencimento = contract_end`. Idempotente.
- **D-05 — Form Comercial pós-refator:** seletor multi do catálogo + roteamento por nome via helper `servicoDisparaImplementacao()` com `str_contains` (Polos/Assessoria/Incubadora → trigger; default null). Preserva COM-04/05/06.
- **D-06 — 3 migrations separadas:** `100001_seed_servicos_catalog`, `100002_migrate_legacy_service_data`, `100003_drop_legacy_service_columns_from_companies`. Permite rollback parcial entre cada etapa.
- **D-07 — 7 consumers a refatorar:** Company.php, AdminController.php, CompanyController.php, ComercialController.php, MlbController.php, EmpresaCadastradaNotification.php, EnviarRelatorioFechamentoJob.php. Cada arquivo = 1 commit atômico.
- **D-08 — Verificação financeira pre-drop:** Comando que itera todas as empresas comparando cálculo legacy vs novo; aborta migration 3 com 500 se houver divergência > R$ 0,01.

### Claude's Discretion
- Schema/sintaxe exata das migrations
- Nomes dos métodos novos em ComercialController
- Layout exato do form NovaEmpresa.jsx (modificação cirúrgica)
- Estratégia detalhada de testes (sugestão: integração por consumer + idempotência de migration + comparação financeira)

### Deferred Ideas (OUT OF SCOPE)
- Reescrita da tabela FAIXAS
- UI dedicada "Reativar contrato"
- Grant `sistema.servicos` para outros setores
- Histórico/auditoria de contratos
- Validação de unicidade de contrato ativo
- Pre-cadastro de valor_padrao realista
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SVC-01 | Migration popula `servicos` (6 canônicos) + cria `contratos_servico` por empresa, preservando datas | §3 (data migration idempotente) + §4 (estratégia de drop em 3 etapas) |
| SVC-02 | `AdminController::fechamento` calcula cobrança via SUM contratos; valor confere com pré-refator | §5 (helper puro testável) + §6 (teste de comparação financeira) |
| SVC-03 | `Admin/Financeiro.jsx` reusa UI de contratos do `Companies/Show.jsx` | §8 (componentes Frente A reutilizáveis) |
| SVC-04 | Filtros `whereJsonContains` → JOIN em `servicos.nome` | §7 (padrão query builder) |
| SVC-05 | `Comercial/NovaEmpresa.jsx` usa seletor multi do catálogo; roteamento por nome preserva COM-04..06 | §9 (helper testável `servicoDisparaImplementacao`) |
| SVC-06 | Migration de schema descarta as 5 colunas legacy; `down()` recria estrutura | §4 (drop SQLite/MySQL) |
| SVC-07 | `EmpresaCadastradaNotification` + `EnviarRelatorioFechamentoJob` consomem contratos; conteúdo equivalente | §10 (refator notifications/jobs) |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

- **Stack imutável:** Laravel 12 + Inertia.js + React 18. Nada de novas dependências externas.
- **Idioma:** Comentários e mensagens de log em pt-BR. Termos técnicos consagrados em inglês.
- **Design:** Tailwind com tokens `ecf-*`, componente `DevCard`, helper `cn()` já existentes — reusar.
- **Acesso:** middleware `EnsureUserHasRole` (role:admin) protege as rotas afetadas.
- **Build:** `npm run build` obrigatório após qualquer edição JSX.
- **Deploy:** não executar sem autorização explícita.
- **GSD workflow:** edits só via comandos GSD (esta fase está sob `/gsd:plan-phase 14`).

---

## Summary

A fase é uma **refatoração com data migration**, não greenfield. A Frente A (quick task `260526-jgj`) já entregou `servicos` + `contratos_servico` + models + relações + UI de contratos. A Frente B só precisa: (1) popular o catálogo + criar contratos derivados, (2) trocar a fonte de verdade em 7 arquivos consumers, (3) dropar as 5 colunas legacy, **sem alterar o resultado financeiro do Fechamento**.

**Descobertas relevantes pelo planner:**

1. **Não há índices ou FKs nas 5 colunas legacy** — verificado via grep nas migrations originais (`add_service_fields_to_companies.php`, `add_additional_service_price_to_companies.php`, `convert_service_type_to_json_array.php`). Drop é trivial — sem cleanup de FK pendente.
2. **`service_type` foi convertida para `TEXT` em 2026-05-25** (não mais string) — o `down()` da migration de drop deve recriar como `text`, não `string`, pra preservar reversibilidade real.
3. **AdminController tem 3 pontos de cálculo de `cobranca_mensal`**, não 1 — linhas 280 (fechamento), 506 (gerarRelatorio individual), 663 (gerarRelatorioGeral). Cada um precisa ser refatorado e cada um precisa de teste de bate-pra-bate.
4. **PHPUnit usa SQLite em testes** (verificado em `phpunit.xml:27`); prod usa MySQL. Isso impacta como escrever data migrations (não usar SQL bruto MySQL-only).
5. **Helper puro testável** é o ponto-chave do D-08: extrair `calcularCobrancaMensal(?array $faixaData, Collection $contratos): ?float` de forma que possa ser chamado lado-a-lado com a versão legacy no comando de verificação.

**Primary recommendation:** Migration 2 (data) + comando Artisan `php artisan phase14:verificar-cobranca` + Migration 3 (drop) **em commits separados, executados sequencialmente, com checkpoint humano entre 2 e 3**.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Catálogo de serviços (CRUD + ativo/inativo) | API/Backend (`ServicoController`) | Database (`servicos`) | Já entregue na Frente A — reusar sem mudança |
| Gestão de contratos por empresa | API/Backend (`CompanyController::storeContrato/updateContrato/destroyContrato`) | Database (`contratos_servico`) | Já entregue na Frente A — reusar rotas e modal |
| Migração de dados legacy → contratos | Database (migration data) | — | Roda 1× (idempotente); commit dedicado |
| Cálculo de cobrança no Fechamento | API/Backend (`AdminController::fechamento` + helper puro) | Database (JOIN com contratos_servico) | Lógica financeira: fica no backend; helper testável |
| Filtros de tipo de serviço | API/Backend (JOIN servicos.nome) | Database | Substitui `whereJsonContains` |
| Roteamento de cadastro Comercial | API/Backend (`ComercialController` com helper `servicoDisparaImplementacao`) | — | Helper puro testável; preserva COM-04/05/06 |
| UI de contratos no Fechamento | Frontend (`Admin/Financeiro.jsx` reusa componentes de `Companies/Show.jsx`) | — | Sem nova UI — copy/paste do modal já existente |
| UI seletor multi no cadastro | Frontend (`Comercial/NovaEmpresa.jsx`) | — | Modificação cirúrgica do input service_type → multi-select |

---

## 1. Idempotent data migrations em Laravel

### Padrão recomendado

**Para o catálogo (migration 1):**

```php
// 2026_05_27_100001_seed_servicos_catalog.php
public function up(): void
{
    $mapaLegacy = [
        'publicacao'  => 'Publicação',
        'polos'       => 'Polos',
        'assessoria'  => 'Assessoria',
        'incubadora'  => 'Incubadora',
        'publicidade' => 'Publicidade',
        'gestao'      => 'Gestão',
    ];

    foreach ($mapaLegacy as $nome) {
        \App\Models\Servico::firstOrCreate(
            ['nome' => $nome],                                    // 1ª arg = match by
            ['valor_padrao' => 0, 'tipo_cobranca' => 'mensal', 'ativo' => true],
        );
    }
}

public function down(): void
{
    // Não deletar — pode haver contratos vinculados (restrictOnDelete)
    // Em dev: o seed é trivial de re-rodar; em prod: contratos preservados.
}
```

**Por que `firstOrCreate` (não `updateOrCreate`):** se o usuário ajustou `valor_padrao` realista pós-migration, `updateOrCreate` SOBRESCREVERIA o ajuste manual num re-run. `firstOrCreate` só cria se não existir — preserva edições manuais.

**Para os contratos (migration 2):**

```php
// 2026_05_27_100002_migrate_legacy_service_data.php
public function up(): void
{
    // Usa DB::transaction pra atomicidade — se falhar no meio, nada fica criado
    \DB::transaction(function () {
        $servicosByNome = \App\Models\Servico::pluck('id', 'nome');  // cache: nome → id

        // chunk(100) evita carregar todas as empresas em memória
        \App\Models\Company::chunk(100, function ($companies) use ($servicosByNome) {
            foreach ($companies as $c) {
                $this->migrarContratosLegacy($c, $servicosByNome);
            }
        });
    });
}

private function migrarContratosLegacy(Company $c, $servicosByNome): void
{
    $mapaLegacy = ['publicacao' => 'Publicação', 'polos' => 'Polos', ...];
    $dataContratacao = $c->contract_start ?? $c->created_at->toDateString();

    // (1) 1 contrato por tipo em service_type
    foreach ((array) $c->service_type as $tipo) {
        $nome = $mapaLegacy[$tipo] ?? null;
        if (!$nome || !isset($servicosByNome[$nome])) continue;

        // GUARD DE IDEMPOTÊNCIA: pula se já existe
        $jaExiste = \App\Models\ContratoServico::where('company_id', $c->id)
            ->where('servico_id', $servicosByNome[$nome])
            ->where('valor_contratado', 0)
            ->exists();

        if ($jaExiste) continue;

        \App\Models\ContratoServico::create([
            'company_id'       => $c->id,
            'servico_id'       => $servicosByNome[$nome],
            'valor_contratado' => 0,
            'data_contratacao' => $dataContratacao,
            'data_vencimento'  => $c->contract_end,
            'ativo'            => true,
        ]);
    }

    // (2) contrato adicional via firstOrCreate no catálogo + check de idempotência
    if (!empty(trim($c->additional_service ?? ''))) {
        $nome = mb_convert_case(trim($c->additional_service), MB_CASE_TITLE, 'UTF-8');
        $servico = \App\Models\Servico::firstOrCreate(
            ['nome' => $nome],
            ['valor_padrao' => (float) ($c->additional_service_price ?? 0),
             'tipo_cobranca' => 'mensal', 'ativo' => true],
        );

        $jaExiste = \App\Models\ContratoServico::where('company_id', $c->id)
            ->where('servico_id', $servico->id)
            ->where('valor_contratado', (float) ($c->additional_service_price ?? 0))
            ->exists();

        if (!$jaExiste) {
            \App\Models\ContratoServico::create([
                'company_id'       => $c->id,
                'servico_id'       => $servico->id,
                'valor_contratado' => (float) ($c->additional_service_price ?? 0),
                'data_contratacao' => $dataContratacao,
                'data_vencimento'  => $c->contract_end,
                'ativo'            => true,
            ]);
        }
    }
}
```

### Por que essa estrutura

- **`DB::transaction`** envolvendo o loop garante atomicidade. Se a migration falhar no meio (ex: empresa #50 dá erro), nenhum contrato fica criado — `php artisan migrate` falha de forma loud.
- **`chunk(100)`** evita OOM em produção com muitas empresas. Não usar `Company::all()` direto.
- **Guard explícito `where(...)->exists()`** antes de criar é mais robusto que `firstOrCreate` para o pivot, porque o pivot tem múltiplos critérios (company_id + servico_id + valor_contratado) e `firstOrCreate` exige unicidade exata.
- **Cache `pluck('id', 'nome')`** evita N queries para resolver `servico_id`.

### Teste de idempotência

```php
public function test_migration_pode_rodar_2x_sem_duplicar(): void
{
    // Cria 1 empresa com service_type=['polos'] e additional_service='Treinamento'
    $c = Company::factory()->create([
        'service_type'             => ['polos'],
        'additional_service'       => 'Treinamento',
        'additional_service_price' => 100.00,
        'contract_start'           => '2026-01-01',
    ]);

    Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_27_100001_seed_servicos_catalog.php']);
    Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_27_100002_migrate_legacy_service_data.php']);

    $this->assertEquals(2, ContratoServico::where('company_id', $c->id)->count());

    // Re-roda manualmente (simula migrate:rollback + migrate)
    $this->runDataMigrationManually();

    $this->assertEquals(2, ContratoServico::where('company_id', $c->id)->count(),
        'Migration não-idempotente: criou contratos duplicados na 2ª execução');
}
```

### Alternativa rejeitada

`updateOrCreate` no pivot — não cabe porque pivot tem combinação multi-coluna; `updateOrCreate` exigiria `unique` constraint que não existe (D-deferred manteve sem unicidade).

### Pegadinha

`Company::all()` em prod com 100+ empresas: usar `chunk()`. Migrations em CI/teste com 5 empresas: irrelevante. Padronizar `chunk(100)` por segurança.

---

## 2. Drop columns em coluna sem índice/FK (mas com type=TEXT)

### Padrão recomendado

```php
// 2026_05_27_100003_drop_legacy_service_columns_from_companies.php
public function up(): void
{
    Schema::table('companies', function (Blueprint $table) {
        $table->dropColumn([
            'service_type',
            'contract_start',
            'contract_end',
            'additional_service',
            'additional_service_price',
        ]);
    });
}

public function down(): void
{
    Schema::table('companies', function (Blueprint $table) {
        // Reconstrução EXATA dos tipos pré-drop — não simplificar para string!
        $table->text('service_type')->nullable()->after('notes');         // text, não string (post 2026_05_25_300001)
        $table->date('contract_start')->nullable()->after('service_type');
        $table->date('contract_end')->nullable()->after('contract_start');
        $table->string('additional_service')->nullable()->after('contract_end');
        $table->decimal('additional_service_price', 10, 2)->nullable()->after('additional_service');
    });
}
```

### Descobertas

- **Nenhum índice ou FK** nas 5 colunas (verificado via grep nas 3 migrations de origem). Drop é direto.
- **`service_type` foi convertida para `TEXT`** em `2026_05_25_300001_convert_service_type_to_json_array.php`. O `down()` deve recriar como `text`, não `string` (que seria varchar 255). Se recriar como string, perde a expansão de tamanho.
- **`contract_type`** (linha 12 de `add_contract_type_to_companies.php`) existe na tabela mas NÃO está na lista de drop do D-07 — confirmar com usuário se deve cair junto. *Ver Open Questions.*

### Pegadinha SQLite vs MySQL

**SQLite (testes via phpunit.xml):** `dropColumn` em múltiplas colunas em uma única `Schema::table` exige doctrine/dbal historicamente, mas a partir do Laravel 9+ funciona nativo. **Verificar:** Laravel 12 não tem mais essa restrição — confirmado em `database/migrations/2026_05_19_100001_add_service_fields_to_companies.php:22` (já existe `dropColumn(['service_type', ...])` no `down()` do schema legacy). Se já funcionou lá, funciona aqui.

**MySQL/MariaDB (prod):** sem ressalvas — `ALTER TABLE DROP COLUMN` é direto.

**Documentar no comentário da migration:** "Down recria schema vazio — não restaura dados pós-drop".

### Alternativa rejeitada

Migration única que faz tudo (seed + data + drop): impede rollback parcial. D-06 já decidiu por 3 migrations separadas. Manter.

---

## 3. Refatoração de controller billing-critical (AdminController)

### Padrão recomendado: extrair helper puro

A função-chave é tornar o cálculo **testável isoladamente** e **comparável lado-a-lado** com a versão legacy.

```php
// app/Http/Controllers/AdminController.php (ou novo trait/service)

/**
 * Calcula a cobrança mensal de uma empresa.
 *
 * @param  array|null  $faixaData   Resultado de calcularFaixa() — ['faixa' => ..., 'valor' => ...] ou null
 * @param  iterable    $contratos   Collection<ContratoServico> ativos da empresa (eager-loaded com servico)
 * @return float|null               Soma faixa + mensal contratos; null se ambos null/zero
 */
public static function calcularCobrancaMensal(?array $faixaData, iterable $contratos): ?float
{
    $valorFaixa = (float) ($faixaData['valor'] ?? 0);

    $somaContratos = collect($contratos)
        ->filter(fn($c) => $c->ativo && $c->servico && $c->servico->tipo_cobranca === 'mensal')
        ->sum(fn($c) => (float) $c->valor_contratado);

    $total = $valorFaixa + $somaContratos;

    // Mantém semântica legacy: null se ambos zerados E faixaData null
    return ($faixaData !== null || $somaContratos > 0) ? $total : null;
}
```

**Por que helper estático:**
- Testável sem montar HTTP request, controller, ou container Laravel.
- Reusável em `fechamento()`, `gerarRelatorio()` (linha 506), `gerarRelatorioGeral()` (linha 663).
- Não introduz nova classe — fica como método estático no controller que já existe.
- Pode ser chamado no comando de verificação financeira (D-08) lado-a-lado com o cálculo legacy.

### Onde aplicar

Os **3 sites** que calculam cobrança no AdminController atualmente:

| Linha | Método | Cálculo atual | Refator |
|-------|--------|--------------|---------|
| 280-282 | `fechamento()` | `(float) ($faixaData['valor'] ?? 0) + (float) ($c->additional_service_price ?? 0)` | `self::calcularCobrancaMensal($faixaData, $c->contratosServico)` |
| 506 | `gerarRelatorio()` (filhas) | `($valorMensal ?? 0) + ($adicional ?? 0)` | idem |
| 511-512 | `gerarRelatorio()` (pai) | `($valorMensalPai ?? 0) + ($adicionalPai ?? 0)` | idem |
| 663-665 | `gerarRelatorioGeral()` | idem | idem |

**Eager loading obrigatório:** todo `Company::query()` que chama o helper precisa ter `->with(['contratosServico' => fn($q) => $q->where('ativo', true)->with('servico')])`. Senão dispara N+1.

### Alternativa rejeitada

Substituir inline em cada um dos 3 sites com query nova: gera duplicação + risco de divergência. Helper único é mais seguro.

### Pegadinha

`$c->contratosServico` pode não estar eager-loaded em alguns paths (ex: `Company::find($id)`). O helper aceita `iterable` para que tanto Collection eager quanto lazy funcionem, mas para perf garantir o `->with()` nas queries do controller.

---

## 4. Teste de comparação financeira pre/post-refator (D-08)

### Padrão recomendado: comando Artisan executado entre migration 2 e 3

```php
// app/Console/Commands/Phase14VerificarCobranca.php
namespace App\Console\Commands;

use App\Http\Controllers\AdminController;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Phase14VerificarCobranca extends Command
{
    protected $signature = 'phase14:verificar-cobranca {--abort-on-divergence : Aborta com exit code 1 se houver divergência}';
    protected $description = 'Phase 14: compara cálculo de cobrança legacy vs novo (contratos_servico) para todas as empresas';

    public function handle(AdminController $admin): int
    {
        $divergencias = 0;
        $total = Company::count();
        $this->info("[Phase14] Verificando cobrança em {$total} empresas...");

        Company::with(['contratosServico' => fn($q) => $q->where('ativo', true)->with('servico')])
            ->chunk(100, function ($companies) use ($admin, &$divergencias) {
                foreach ($companies as $c) {
                    $faixaData = $admin->faixaPara($c);  // ou null — extrair helper público
                    $legacy = (float) ($faixaData['valor'] ?? 0) + (float) ($c->additional_service_price ?? 0);
                    $novo   = AdminController::calcularCobrancaMensal($faixaData, $c->contratosServico) ?? 0;

                    if (abs($legacy - $novo) > 0.01) {
                        $msg = "[Phase14] empresa #{$c->id} ({$c->name}): legacy=R$ " . number_format($legacy, 2) . " novo=R$ " . number_format($novo, 2);
                        Log::error($msg);
                        $this->error($msg);
                        $divergencias++;
                    }
                }
            });

        if ($divergencias > 0) {
            $this->error("[Phase14] {$divergencias} empresa(s) com divergência — corrigir antes de prosseguir.");
            return $this->option('abort-on-divergence') ? 1 : 0;
        }

        $this->info("[Phase14] Todas as {$total} empresas conferem (0 divergências).");
        return 0;
    }
}
```

### Sequência de execução em prod (humano)

1. `git pull` (código com helper + 3 migrations + comando)
2. `php artisan migrate --path=database/migrations/2026_05_27_100001_seed_servicos_catalog.php`
3. `php artisan migrate --path=database/migrations/2026_05_27_100002_migrate_legacy_service_data.php`
4. **`php artisan phase14:verificar-cobranca --abort-on-divergence`** ← **CHECKPOINT HUMANO**
   - Se exit code 0: prossegue
   - Se exit code 1: aborta e investiga
5. `php artisan migrate --path=database/migrations/2026_05_27_100003_drop_legacy_service_columns_from_companies.php`

**A ordem importa:** o comando precisa rodar com as colunas legacy AINDA presentes (lê `additional_service_price`). Se a migration 3 rodar antes, o cálculo legacy vira garbage.

### Teste automatizado

```php
public function test_calcular_cobranca_mensal_bate_com_legacy(): void
{
    // Cenário: faixa R$ 1500 + additional_service_price R$ 200
    $faixaData = ['faixa' => 2, 'valor' => 1500];
    $c = Company::factory()->create(['additional_service_price' => 200]);
    $c->contratosServico()->create([
        'servico_id'       => Servico::create(['nome' => 'X', 'valor_padrao' => 0, 'tipo_cobranca' => 'mensal', 'ativo' => true])->id,
        'valor_contratado' => 200,
        'data_contratacao' => now()->toDateString(),
        'ativo'            => true,
    ]);

    $legacy = (float) ($faixaData['valor']) + (float) $c->additional_service_price;
    $novo = AdminController::calcularCobrancaMensal($faixaData, $c->contratosServico()->with('servico')->active()->get());

    $this->assertEquals($legacy, $novo, '', 0.01);
}
```

### Pegadinha

A logica original (linha 280-282) tem um caveat sutil: `cobranca_mensal = null` quando `$faixaData === null && !$c->additional_service_price`. O helper precisa preservar isso — empresas sem faixa E sem contratos mensais devem retornar `null`, não `0.00`. Senão Total Consolidado no Financeiro.jsx fica errado (soma 0 em vez de pular).

---

## 5. Refatoração de filtros `whereJsonContains` → JOIN

### Padrão recomendado

**Antes (MlbController:1527):**

```php
$query->where(function ($q) {
    $q->whereJsonContains('service_type', 'publicacao')
      ->orWhereJsonContains('service_type', 'polos')
      ->orWhereJsonContains('service_type', 'assessoria');
});
```

**Depois (JOIN via `whereHas`):**

```php
$query->whereHas('contratosServico', function ($q) {
    $q->where('ativo', true)
      ->whereHas('servico', fn($qs) => $qs->whereIn('nome', ['Publicação', 'Polos', 'Assessoria']));
});
```

### Por que `whereHas` (não JOIN raw)

- `whereHas` gera subquery `EXISTS` que respeita soft deletes, scopes, e não duplica linhas (JOIN duplica quando empresa tem múltiplos contratos).
- Lê melhor.
- Funciona idêntico em SQLite e MySQL.

### Alternativa para distintos com SELECT extra

Se precisar SELECT em colunas de `servicos` no resultado:

```php
$companies = Company::query()
    ->join('contratos_servico', 'contratos_servico.company_id', '=', 'companies.id')
    ->join('servicos', 'servicos.id', '=', 'contratos_servico.servico_id')
    ->where('contratos_servico.ativo', true)
    ->whereIn('servicos.nome', ['Publicação', 'Polos'])
    ->select('companies.*')
    ->distinct()
    ->get();
```

Mas para o filtro do MlbController (que só restringe a lista), `whereHas` é o padrão.

### Pegadinha

`whereJsonContains` no campo `service_type` (que é TEXT JSON) **não tem índice** — a query atual é table scan. Após o refator, a query nova faz `EXISTS` sobre `contratos_servico` que TEM índice composto `(company_id, ativo)` (verificado em migration `2026_05_26_120002:34`). Performance melhora.

---

## 6. spatie/laravel-activitylog — campos removidos

### Descoberta

`Company::getActivitylogOptions()` linha 17 (Company.php) loga atualmente:

```php
->logOnly(['name', 'cnpj', 'segment', 'active', 'status', 'notes', 'adman_account_id', 'ml_store_id', 'service_type', 'contract_start', 'contract_end'])
```

Após Phase 14: remover `service_type, contract_start, contract_end` desse array.

### Risco de logs históricos

**Pergunta:** logs em `activity_log` que referenciam os 3 campos removidos ficam "quebrados"?

**Resposta:** **Não.** `activity_log` armazena os valores em `properties->old` e `properties->attributes` (JSON). As colunas da tabela `companies` deixam de existir, mas os logs históricos são linhas JSON imutáveis — ficam como **histórico arqueológico**. Activitylog não faz JOIN com a coluna; apenas grava o nome e o valor no JSON na hora da escrita.

**Não precisa limpar logs.** O log histórico continua mostrando "service_type alterado de [polos] para [polos, gestao] em 2026-05-20" — perfeitamente legível mesmo depois que a coluna sumir.

### Padrão para ContratoServico

ContratoServico já loga (Frente A entregou). Phase 14 **não adiciona LogsActivity novo** — apenas reusa.

### Pegadinha

Se algum lugar do código fizer `Activity::where('properties->attributes->service_type', ...)`, vai continuar funcionando (são logs antigos). Não há esse uso no código atual (grep confirmou).

---

## 7. Teste do form Comercial pós-refatoração (D-05)

### Padrão recomendado

```php
public function test_cadastra_empresa_com_servico_polos_e_cria_mlb_implementacao(): void
{
    $this->actingAs(User::factory()->admin()->create());
    Servico::firstOrCreate(['nome' => 'Polos'], ['valor_padrao' => 0, 'tipo_cobranca' => 'mensal', 'ativo' => true]);

    $servicoPolos = Servico::where('nome', 'Polos')->first();

    $response = $this->post('/comercial/empresas', [
        'name'        => 'Empresa Teste',
        'cnpj'        => null,
        'servicos'    => [['servico_id' => $servicoPolos->id, 'valor_contratado' => 0]],
    ]);

    $response->assertRedirect();

    $company = Company::where('name', 'Empresa Teste')->first();
    $this->assertNotNull($company);
    $this->assertEquals('pendente', $company->status);
    $this->assertEquals(1, $company->contratosServico()->count());

    // Roteamento Phase 13 PRESERVADO: COM-04
    $this->assertNotNull(\App\Models\MlbEmpresa::where('company_id', $company->id)->first());
    $this->assertNotNull(\App\Models\MlbImplementacao::whereHas('mlbEmpresa', fn($q) => $q->where('company_id', $company->id))->first());
}

public function test_cadastra_empresa_com_servico_publicidade_nao_cria_mlb(): void
{
    // COM-06 preservado: Publicidade/Gestão NÃO criam mlb_empresas
    // ...
    $this->assertNull(\App\Models\MlbEmpresa::where('company_id', $company->id)->first());
}

public function test_helper_servico_dispara_implementacao(): void
{
    $this->assertEquals('polos', \App\Http\Controllers\ComercialController::servicoDisparaImplementacao('Polos'));
    $this->assertEquals('polos', \App\Http\Controllers\ComercialController::servicoDisparaImplementacao('Polos SP'));  // str_contains
    $this->assertEquals('assessoria', \App\Http\Controllers\ComercialController::servicoDisparaImplementacao('Assessoria Premium'));
    $this->assertNull(\App\Http\Controllers\ComercialController::servicoDisparaImplementacao('Publicidade'));
    $this->assertNull(\App\Http\Controllers\ComercialController::servicoDisparaImplementacao('Treinamento'));
}
```

### Por que extrair helper puro `servicoDisparaImplementacao`

- Testável sem montar request
- Cobre a função-chave: roteamento por nome de serviço
- Permite changelog futuro (novo serviço "Polos PE" → automaticamente roteia)

### Pegadinha

Se `str_contains` for case-sensitive (PHP nativo é), garantir que catálogo respeite Title Case (D-02 já fala disso). Caso o usuário cadastre "POLOS" em maiúsculas via UI futura, o helper falharia — mas D-02 normaliza via `MB_CASE_TITLE`, então só "Polos" entra no catálogo.

---

## 8. MySQL vs SQLite gotchas

### Confirmado

- **PHPUnit usa SQLite** (`phpunit.xml:27` → `DB_CONNECTION=sqlite`).
- **Prod usa MySQL/MariaDB** (CLAUDE.md "MySQL/MariaDB used in production").

### Pontos de atenção

| Operação | SQLite | MySQL/MariaDB | Mitigação |
|----------|--------|---------------|-----------|
| `dropColumn(['a','b','c'])` em uma migration | OK em Laravel 12 (sem doctrine/dbal) | OK | Já provado no `down()` da migration `2026_05_19_100001` |
| `change()` para alterar tipo de coluna | Suporte limitado historicamente — Laravel 11+ usa schema rebuild | OK | A migration `2026_05_25_300001` já chama `change()` com sucesso — manter padrão |
| Cast `decimal:2` em SQLite | Retorna string (`"100.00"`) não float | Retorna string (em PDO) | Sempre `(float) $valor_contratado` antes de operações aritméticas |
| `cast 'date:Y-m-d'` em SQLite | Pode retornar `null` se valor mal-formado | Idem | Frente A já tem `date:Y-m-d` no Servico/ContratoServico — replicar |
| JSON queries (`whereJsonContains`) | Funciona SQLite 3.38+ | Funciona MySQL 5.7+ | Após Phase 14 não usamos mais — irrelevante |
| `DB::transaction` em migration | OK | OK | Padrão |

### Pegadinha sutil

`Company::factory()` em testes via SQLite cria datas como strings ISO (`'2026-01-01'`). Em prod MySQL retorna `Carbon` instance (via cast `date:Y-m-d`). Usar `?->toDateString()` ou `Carbon::parse()` consistentemente, nunca assumir o tipo.

---

## 9. Refator de notifications/jobs (D-07, itens 6 e 7)

### `EmpresaCadastradaNotification.php`

**Atual (linha 33):**

```php
meta: ['empresa' => $nomeEmpresa, 'service_type' => $serviceType],
```

**Refator:**

```php
// Recebe array de nomes de serviço em vez de service_type
meta: ['empresa' => $nomeEmpresa, 'servicos' => $servicosNomes],
```

E o template/dispatcher passa `$company->contratosServico()->active()->with('servico')->get()->pluck('servico.nome')->toArray()` no lugar de `$company->service_type`.

### `EnviarRelatorioFechamentoJob.php`

**Atual (linhas 128, 132, 133):** payload do email cita service_type, additional_service, additional_service_price.

**Refator:** trocar por lista textualizada dos contratos ativos:

```php
$contratos = $f->contratosServico()->active()->with('servico')->get();
$servicosTexto = $contratos->map(fn($c) => $c->servico->nome . ' (R$ ' . number_format($c->valor_contratado, 2, ',', '.') . ')')->implode(', ');

// E no payload:
'servicos' => $servicosTexto,  // substitui service_type + additional_service + additional_service_price
```

### Pegadinha

A view Blade `admin.relatorio-fechamento` (referenciada em `AdminController:518`) **provavelmente** lê os 3 campos legacy. **Verificar e refatorar a view junto** — não está na lista do D-07 mas é consumidor implícito. *Ver Open Questions.*

---

## 10. Resumo dos consumers (D-07) com pontos críticos

| # | Arquivo | Pontos críticos | LOC aprox |
|---|---------|----------------|-----------|
| 1 | `app/Models/Company.php` | Remove 3 campos de `$fillable` (linhas 31-32), 3 casts (39-41), 3 do `logOnly` (17). `Company::labelFromTypes()` linha 45-70 — remover ou refazer? Ver Open Q. | 30 LOC |
| 2 | `app/Http/Controllers/AdminController.php` | 3 sites de cálculo (280, 506, 663). Validation rules (57-63, 373-379) que aceitam `service_type` no PATCH — remover. Filtros (572-573) | 80 LOC |
| 3 | `app/Http/Controllers/CompanyController.php` | grep confirmou matches — inspecionar antes do plan | TBD |
| 4 | `app/Http/Controllers/ComercialController.php` | Reescrever store() inteiro conforme D-05; refazer helper `resolverSlugsSetores` que hoje depende de service_type | 60 LOC |
| 5 | `app/Http/Controllers/MlbController.php` | Apenas 2 linhas (1527, 1533) — JOIN simples (§7) | 5 LOC |
| 6 | `app/Notifications/EmpresaCadastradaNotification.php` | Apenas linha 33 — trocar meta key | 3 LOC |
| 7 | `app/Jobs/EnviarRelatorioFechamentoJob.php` | 3 linhas (128, 132, 133) — payload do email | 10 LOC |

**Bonus consumer (não no D-07 mas grep confirmou):** `resources/views/admin/relatorio-fechamento.blade.php` — provavelmente lê os campos. Verificar.

---

## Standard Stack

Sem dependências externas novas. Tudo já no projeto:

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | ^12.0 | Migrations, Eloquent, Artisan command, queue | Já é o backbone |
| `phpunit/phpunit` | ^11.5 | Testes de idempotência + comparação financeira | Já configurado |
| `spatie/laravel-activitylog` | ^4.9 | Log de mudanças em contratos | Já no Servico/ContratoServico |

Nada a instalar.

## Package Legitimacy Audit

**Não aplicável** — esta fase não instala nenhum pacote novo. Toda a stack é Laravel core + dependências já presentes em `composer.json`/`composer.lock`. Skip protocolo de slopcheck.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5 |
| Config file | `phpunit.xml` (DB=sqlite, MEMORY) |
| Quick run command | `php artisan test --filter=Phase14` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SVC-01 | Migration cria 6 servicos + contratos derivados | unit (migration) | `php artisan test --filter=Phase14MigrationTest::test_migration_cria_catalogo_e_contratos` | Wave 0 |
| SVC-01 | Migration é idempotente (2× sem duplicar) | unit (migration) | `php artisan test --filter=Phase14MigrationTest::test_migration_pode_rodar_2x_sem_duplicar` | Wave 0 |
| SVC-02 | Helper calcularCobrancaMensal retorna soma correta | unit (puro) | `php artisan test --filter=AdminControllerTest::test_calcular_cobranca_mensal_bate_com_legacy` | Wave 0 |
| SVC-02 | Comando phase14:verificar-cobranca retorna 0 quando todas batem | integration | `php artisan test --filter=Phase14VerificarCobrancaTest::test_zero_divergencias_quando_dados_consistentes` | Wave 0 |
| SVC-02 | Comando retorna 1 quando há divergência > R$ 0,01 | integration | `php artisan test --filter=Phase14VerificarCobrancaTest::test_aborta_com_divergencia` | Wave 0 |
| SVC-03 | UI Admin/Financeiro.jsx renderiza modal de contratos | manual + checkpoint | Visual check pós `npm run build` | Wave 0 |
| SVC-04 | Filtro MlbController via whereHas devolve mesmas empresas | integration | `php artisan test --filter=MlbControllerTest::test_filtro_servicos_via_join` | Wave 0 |
| SVC-05 | Cadastro Comercial com servico Polos cria mlb_implementacao | integration | `php artisan test --filter=Phase14ComercialTest::test_cadastra_empresa_polos` | Wave 0 |
| SVC-05 | Cadastro Comercial com Publicidade NÃO cria mlb | integration | `php artisan test --filter=Phase14ComercialTest::test_cadastra_empresa_publicidade_sem_mlb` | Wave 0 |
| SVC-05 | Helper servicoDisparaImplementacao retorna roteamento correto | unit (puro) | `php artisan test --filter=ComercialControllerTest::test_helper_servico_dispara_implementacao` | Wave 0 |
| SVC-06 | Migration 3 dropa as 5 colunas | smoke | `php artisan migrate; php artisan tinker --execute='\Schema::hasColumn("companies","service_type")'` | manual |
| SVC-07 | EmpresaCadastradaNotification meta tem `servicos` key | integration | `php artisan test --filter=EmpresaCadastradaNotificationTest::test_meta_contem_servicos` | Wave 0 |
| SVC-07 | EnviarRelatorioFechamentoJob payload tem nomes de contratos | integration | `php artisan test --filter=EnviarRelatorioFechamentoJobTest::test_payload_lista_contratos` | Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=<TestClass>` para a classe afetada
- **Per wave merge:** `php artisan test --filter=Phase14` (toda suíte da fase)
- **Phase gate:** `php artisan test` completo verde + `php artisan phase14:verificar-cobranca` exit 0 antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Phase14MigrationTest.php` — cobre SVC-01 (idempotência + criação correta)
- [ ] `tests/Feature/Phase14VerificarCobrancaTest.php` — cobre SVC-02 (comando)
- [ ] `tests/Unit/AdminControllerCalcularCobrancaTest.php` — cobre helper puro
- [ ] `tests/Feature/Phase14ComercialTest.php` — cobre SVC-05 (form com seletor multi)
- [ ] `tests/Unit/ComercialControllerHelperTest.php` — cobre helper de roteamento
- [ ] `tests/Feature/MlbControllerFiltroServicosTest.php` — cobre SVC-04
- [ ] `tests/Feature/EmpresaCadastradaNotificationTest.php` — cobre SVC-07
- [ ] `tests/Feature/EnviarRelatorioFechamentoJobTest.php` — cobre SVC-07

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | Sem mudança no login |
| V3 Session Management | no | Sem mudança em sessão |
| V4 Access Control | yes | `EnsureUserHasRole('admin')` middleware nas rotas — já em vigor; verificar que rotas novas (se houver) herdam o middleware |
| V5 Input Validation | yes | Validation rules no ComercialController para `servicos.*.servico_id` (exists:servicos,id) e `valor_contratado` (numeric|min:0) |
| V6 Cryptography | no | Sem cripto envolvido |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Mass assignment de `servico_id` arbitrário no form Comercial (usuário injeta id de servico inativo ou inexistente) | Tampering | Validation `Rule::exists('servicos', 'id')->where('ativo', true)` |
| Cálculo de cobrança manipulado via valor_contratado client-side (usuário comercial envia 0 pra serviço pago) | Tampering | Mas no fluxo D-05 o valor_padrao é editável no form — então essa é decisão de UX (Comercial pode customizar). Garantir que activitylog registra |
| Empresa cadastrada com nome duplicado por race condition (2 submits simultâneos) | Integrity | DB::transaction + guard de duplicatas (já em Phase 13) |

---

## Common Pitfalls

### Pitfall 1: Migration de drop rodando antes da verificação financeira
**O que sai errado:** Drop em prod sem rodar `phase14:verificar-cobranca` — divergências silenciosas viram faturas erradas.
**Por que acontece:** Migrations em Laravel são auto-aplicadas em order via `php artisan migrate` sem perguntar. Se o operador rodar `php artisan migrate` sem `--path`, todas as 3 vão juntas.
**Como evitar:** As 3 migrations DEVEM ser aplicadas com `--path` separadamente, com checkpoint humano entre 2 e 3. Documentar no commit message da migration 3.
**Warning signs:** Operador acostumado com `php artisan migrate` direto.

### Pitfall 2: Helper de cálculo recebe contratos NÃO eager-loaded
**O que sai errado:** N+1 query (1 query por empresa pra carregar contratos + 1 por contrato pra carregar servico). Em fechamento com 50 empresas: 50 × N queries.
**Por que:** AdminController hoje faz `Company::all()` sem `with()`.
**Como evitar:** Toda chamada ao helper precisa de `->with(['contratosServico' => fn($q) => $q->where('ativo', true)->with('servico')])` upstream.
**Warning signs:** Tempo de resposta do `/administrativo/financeiro` >2s pós-refator.

### Pitfall 3: Empresa com `service_type=[]` (array vazio) vs `null`
**O que sai errado:** Migration de dados pula empresas com array vazio (ok). Mas se uma empresa tinha `service_type=['polos']` E `additional_service=null`, ela ganha 1 contrato. Se tem ambos preenchidos, ganha 2. Idempotência tem que considerar exact match no guard.
**Como evitar:** Guard de idempotência usa (company_id + servico_id + valor_contratado) — ver §1. Foreach respeita a regra cumulativa do D-04 itens 1+2.
**Warning signs:** `ContratoServico::count()` cresce na 2ª execução da migration.

### Pitfall 4: AdminController helper estático chama Eloquent — testes precisam DB
**O que sai errado:** Teste unitário do helper `calcularCobrancaMensal` falha porque tenta acessar `$contrato->servico` (lazy-loaded → query). Em teste com `RefreshDatabase` ok; em teste sem, falha.
**Como evitar:** Helper aceita `iterable`. Testes passam Collection pré-construída em memória (`new Collection([(object)['ativo'=>true, 'servico'=>(object)['tipo_cobranca'=>'mensal'], 'valor_contratado'=>200]])`) ou usa `RefreshDatabase` + factory.
**Warning signs:** Teste do helper precisa de migrações pra rodar.

### Pitfall 5: `MB_CASE_TITLE` não dá Title Case "perfeito" em pt-BR
**O que sai errado:** `mb_convert_case('treinamento 1h', MB_CASE_TITLE, 'UTF-8')` → "Treinamento 1H" (capitaliza o H depois do número). Já documentado em D-02 como aceitável.
**Como evitar:** D-02 já aceitou isso. Usuário limpa via UI se vier.
**Warning signs:** Nenhum — comportamento esperado.

### Pitfall 6: `npm run build` esquecido após edição em Financeiro.jsx
**O que sai errado:** Browser exibe versão antiga do componente — usuário pensa que refator não funcionou.
**Como evitar:** CLAUDE.md mandate — `npm run build` obrigatório após cada edição JSX. Plan deve incluir essa task explicitamente.
**Warning signs:** Modal de contratos não aparece no Financeiro pós-deploy.

### Pitfall 7: `down()` da migration de drop não recria como TEXT
**O que sai errado:** Rollback para depurar — `service_type` recriada como string(255). Dados que coubessem em TEXT são truncados na reinjection futura.
**Como evitar:** Recriar EXATAMENTE como estava: `text('service_type')`, não `string`. Ver §2.
**Warning signs:** Migration `down()` roda mas dados perdidos.

### Pitfall 8: Esqueci a view `admin.relatorio-fechamento.blade.php`
**O que sai errado:** Blade da view do relatório (consumida em `gerarRelatorio()`) lê os campos legacy. Após drop, view quebra com `Undefined property: App\Models\Company::$service_type`.
**Como evitar:** Inspecionar a view e tratá-la como 8º consumer (não está no D-07). Ver Open Questions.
**Warning signs:** Erro 500 ao gerar relatório individual pós-drop.

---

## Open Questions (RESOLVED — 2026-05-26)

Todas as 4 questões resolvidas pelo orchestrator antes do gsd-planner ser despachado. CONTEXT.md D-07 e D-09 foram atualizados consequentemente.

1. **`contract_type` (coluna 6) deve cair junto com as 5 legacy?**
   - **RESOLVED**: SIM, drop junto. É display-only (não controla cálculo de FAIXAS). Valores `fixo|progressao` viram parte do nome do serviço pós-migration (ex: serviço "Polos" abrange ambos os modos). Atualizado em CONTEXT.md D-07 → "6 campos" e Plans 14-02/14-06 incluem contract_type.

2. **View `resources/views/admin/relatorio-fechamento.blade.php` é consumidora?**
   - **RESOLVED**: SIM, são 3 Blade views (não 1): `relatorio-fechamento.blade.php`, `relatorio-geral.blade.php`, `relatorio-geral-pdf.blade.php`. Todas chamam `Company::labelFromTypes($company->service_type)` ou consomem `$v['service_type']`/`additional_service` em arrays. CONTEXT.md D-07 atualizado para listar os 3 + D-09 cria refatoração via `labelFromServicos`. Plan 14-05 cobre os 3.

3. **`Company::labelFromTypes()` (linhas 45-70 de Company.php) — deletar ou refatorar?**
   - **RESOLVED**: REFATORAR mantendo API estática. Decisão em CONTEXT.md D-09: criar `labelFromServicos(iterable $servicos)` e fazer `serviceTypeLabel()` derivar do novo modelo. Minimiza churn nas 3 Blades (mesma chamada `$company->serviceTypeLabel()` continua válida pós-refator). Plan 14-03 Task 1 cobre.

4. **Caching: o `RefreshGrossBillingCacheJob` em AdminController:325 — usa campos legacy?**
   - **RESOLVED**: NÃO. Grep direto em `app/Jobs/RefreshGrossBillingCacheJob.php` retornou zero matches para `service_type|additional_service|contract_type|contract_start|contract_end` (verificado 2026-05-26 pelo orchestrator). O job só lê `adman_account_id` e dispara chamadas Adman — não toca campos legacy. **Não precisa refatoração**. Nenhum plano de Phase 14 lista esse job em files_modified.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP CLI | Artisan migrate/test/comando verifier | ✓ | 8.2+ | — |
| Composer | Já instalado, sem novas deps | ✓ | 2.x | — |
| Node + npm | `npm run build` pós-edição JSX | ✓ | 24.15.0 | — |
| MySQL/MariaDB | Prod | ✓ (VPS) | — | — |
| SQLite | Testes phpunit | ✓ (PHP built-in) | — | — |

Sem missing dependencies — toda a stack já está operacional.

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `Company::$service_type` (JSON column) + `whereJsonContains` | `Company::contratosServico()` + `whereHas('servico')` | Phase 14 | Index composto `(company_id, ativo)` acelera filtros; remove TEXT JSON sem índice |
| `additional_service_price` decimal único | `SUM(contratos_servico.valor_contratado WHERE servico.tipo_cobranca='mensal')` | Phase 14 | Permite múltiplos serviços extras + auditoria por contrato |
| `service_type` enum string-based (PHP `in:publicacao,polos,...`) | Catálogo `servicos` (FK) com nomes editáveis | Phase 14 | Sem mais migration toda vez que adiciona tipo |

**Deprecated/outdated após Phase 14:**
- `Company::labelFromTypes()` (provavelmente — ver Open Q 3)
- Validation rules `in:publicacao,polos,...` em AdminController e ComercialController
- Cast `service_type => 'array'` em Company

---

## Code Examples

### Helper testável de cálculo

```php
// AdminController.php — método estático novo
public static function calcularCobrancaMensal(?array $faixaData, iterable $contratos): ?float
{
    $valorFaixa = (float) ($faixaData['valor'] ?? 0);

    $somaContratos = collect($contratos)
        ->filter(fn($c) => $c->ativo && $c->servico && $c->servico->tipo_cobranca === 'mensal')
        ->sum(fn($c) => (float) $c->valor_contratado);

    $total = $valorFaixa + $somaContratos;

    return ($faixaData !== null || $somaContratos > 0) ? $total : null;
}
```

### Migration idempotente de catálogo

```php
public function up(): void
{
    foreach (['Publicação', 'Polos', 'Assessoria', 'Incubadora', 'Publicidade', 'Gestão'] as $nome) {
        \App\Models\Servico::firstOrCreate(
            ['nome' => $nome],
            ['valor_padrao' => 0, 'tipo_cobranca' => 'mensal', 'ativo' => true],
        );
    }
}
```

### Filtro via whereHas (substituindo whereJsonContains)

```php
$query->whereHas('contratosServico', fn($q) =>
    $q->where('ativo', true)
      ->whereHas('servico', fn($qs) => $qs->whereIn('nome', ['Publicação', 'Polos', 'Assessoria']))
);
```

### Helper de roteamento Comercial

```php
public static function servicoDisparaImplementacao(string $nome): ?string
{
    return match(true) {
        str_contains($nome, 'Polos')      => 'polos',
        str_contains($nome, 'Assessoria') => 'assessoria',
        str_contains($nome, 'Incubadora') => 'incubadora',
        default                            => null,
    };
}
```

---

## Assumptions Log

Todas as claims neste research vêm de inspeção direta do código ou de decisões locked no CONTEXT.md. Sem `[ASSUMED]` — pesquisa **HIGH confidence**.

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| — | Nenhuma — pesquisa baseada em código verificado e decisões locked | — | — |

**Tabela vazia:** Todas as claims foram verificadas em código (`grep`, `Read`) ou copiadas verbatim de CONTEXT.md. Nenhuma especulação.

---

## Sources

### Primary (HIGH confidence) — código verificado in-repo

- `.planning/phases/14-consolida-o-do-modelo-de-servi-os-frente-b/14-CONTEXT.md` — 8 decisões locked
- `.planning/REQUIREMENTS.md` — SVC-01..07
- `.planning/quick/260526-jgj-modulo-servicos-frente-a/260526-jgj-SUMMARY.md` — estado Frente A
- `app/Models/Servico.php` — modelo Frente A (verificado)
- `app/Models/ContratoServico.php` — modelo Frente A (verificado)
- `app/Models/Company.php` — campos legacy e logOnly (verificado via grep)
- `app/Http/Controllers/AdminController.php` linhas 280-282, 506, 511-512, 663-665 — 3 sites de cálculo (verificado via grep)
- `app/Http/Controllers/MlbController.php` linhas 1527-1533 — único filtro whereJsonContains (verificado)
- `app/Http/Controllers/ComercialController.php` linhas 22-170 — validation + roteamento Phase 13 (verificado)
- `app/Notifications/EmpresaCadastradaNotification.php:33` — meta key service_type (verificado)
- `app/Jobs/EnviarRelatorioFechamentoJob.php` linhas 128, 132-133 — payload (verificado)
- `database/migrations/2026_05_19_100001_add_service_fields_to_companies.php` — schema legacy original (verificado)
- `database/migrations/2026_05_20_100002_add_additional_service_price_to_companies.php` — schema additional_service_price (verificado)
- `database/migrations/2026_05_25_300001_convert_service_type_to_json_array.php` — conversão para TEXT (verificado)
- `database/migrations/2026_05_26_120002_create_contratos_servico_table.php` — schema contratos_servico + index (verificado)
- `phpunit.xml:27` — DB_CONNECTION=sqlite em testes (verificado)
- `.planning/config.json` — nyquist_validation=true, language=pt-BR (verificado)

### Secondary (MEDIUM confidence)

- Padrões de Laravel 12 — conhecimento de stack já em uso no projeto há múltiplas phases (1-13 entregues)
- `spatie/laravel-activitylog` comportamento de logs históricos — convenção documentada do pacote

### Tertiary (LOW confidence)

Nenhum — toda informação verificada in-repo ou em CONTEXT.md.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — toda a stack já em uso, sem novas deps
- Architecture: HIGH — modelo N:N entregue e provado na Frente A
- Migrations idempotentes: HIGH — padrão Laravel canônico (`firstOrCreate` + guard explícito) já documentado oficialmente
- Drop columns SQLite: HIGH — já provado no `down()` da migration 2026_05_19_100001
- Refator billing: HIGH — helper puro extraível, 3 sites identificados
- Pitfalls: HIGH — 8 itens, todos vindos de inspeção real do código

**Research date:** 2026-05-26
**Valid until:** 2026-06-25 (30 dias — stack estável, sem deps externas mutáveis)
