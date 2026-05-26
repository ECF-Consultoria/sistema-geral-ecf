---
phase: 14-consolida-o-do-modelo-de-servi-os-frente-b
reviewed: 2026-05-26T00:00:00Z
depth: deep
files_reviewed: 13
files_reviewed_list:
  - app/Support/CobrancaCalculator.php
  - app/Console/Commands/Phase14VerificarCobranca.php
  - app/Models/Company.php
  - app/Http/Controllers/AdminController.php
  - app/Http/Controllers/ComercialController.php
  - app/Http/Controllers/MlbController.php
  - app/Http/Controllers/CompanyController.php
  - app/Notifications/EmpresaCadastradaNotification.php
  - app/Jobs/EnviarRelatorioFechamentoJob.php
  - database/migrations/2026_05_27_100001_seed_servicos_catalog.php
  - database/migrations/2026_05_27_100002_migrate_legacy_service_data.php
  - database/migrations/2026_05_27_100003_drop_legacy_service_columns_from_companies.php
  - resources/js/Pages/Comercial/NovaEmpresa.jsx
  - resources/js/Pages/Admin/Financeiro.jsx
  - resources/views/admin/relatorio-fechamento.blade.php
  - resources/views/admin/relatorio-geral.blade.php
  - resources/views/admin/relatorio-geral-pdf.blade.php
findings:
  critical: 3
  warning: 4
  info: 3
  total: 10
status: clean
blockers_fixed_at: 2026-05-26T00:00:00Z
blockers_fixed_commits:
  - faf06a4  # CR-01: Blade views consomem payload novo (servicos_contratados + cobranca_mensal)
  - b8ce7d4  # CR-02: migration 100002 independente de casts removidos
  - 161e279  # CR-03: Job usa CobrancaCalculator para total_mensalidade (paridade com Controller)
remaining_debt:
  - WR-01, WR-02, WR-03, WR-04  # Warnings — debito
  - IN-01, IN-02, IN-03         # Info — debito
---

# Phase 14 — Code Review Report

**Reviewed:** 2026-05-26
**Depth:** deep (cross-file: migrations + controllers + helper + Blade views + Job)
**Status:** issues_found

## Resumo

Phase 14 entregou o núcleo do refator com qualidade no caminho principal: helper puro testável (`CobrancaCalculator`), data migration idempotente com guard de dedupe (D-04), 3 controllers principais usando `whereHas` + eager loading correto, cadastro Comercial atômico em `DB::transaction`. Convenções pt-BR e tokens `ecf-*` preservadas; nenhum SQL injection, nenhum XSS introduzido, nenhum `eval`/dangerous sink.

**Porém, 3 BLOCKERS comprometem o ship-readiness:**

1. As 3 Blade views de relatório (`relatorio-fechamento`, `relatorio-geral`, `relatorio-geral-pdf`) **ainda referenciam 6 das 6 colunas dropadas** em mais de 50 sites. Após Plan 14-06 aplicado em produção, as rotas `admin/empresas/{id}/relatorio` e `admin/relatorio/geral` retornarão erro 500 (`Undefined property: App\Models\Company::$contract_type` etc.). O Plan 14-05 só substituiu **1 site** por Blade (o campo "Tipo de serviço"), deixando "Tipo de contrato", "Vigência", "Serviço adicional" e blocos de total intactos.
2. A data migration `2026_05_27_100002_migrate_legacy_service_data.php` deixou de funcionar em qualquer ambiente que rode `migrate:fresh` chronologicamente. A migration lê `$company->service_type` e `$company->contract_start` esperando casts `array`/`date` que Plan 14-06 removeu de `Company.php`. Em fresh installs (dev local, CI, novo VPS) a migration silenciosamente NÃO cria contratos derivados ou crash com `Call to a member function toDateString() on string`.
3. `EnviarRelatorioFechamentoJob` calcula `total_mensalidade` SEM somar `valor_contratado` dos contratos ativos. Pre-Phase 14 somava `additional_service_price`; pós-refator perdeu o componente. Os emails de fechamento mostrarão totais MENORES que os exibidos na UI `/administrativo/financeiro` e nos relatórios gerados via web. Regressão financeira silenciosa.

Os 3 BLOCKERS coexistem porque a phase tinha múltiplos consumers da mesma fonte; o Plan 14-05 (UI + Blades) e o Plan 14-03 (Job) trataram **payload** de saída mas esqueceram **reconsumo** legacy interno e **soma de contratos**, respectivamente.

WARNINGS adicionais cobrem: ofuscação de coluna via concat de strings, accessor sem guard contra schema pós-drop, semântica `?: null` que zera contratos legítimos R$ 0, e docblock de `Company.php` partido.

## Findings — Estrutural (fallow)

Não foi fornecido `<structural_findings>` no prompt. Esta seção fica vazia.

## Findings — Narrativa (AI reviewer)

## Critical

### CR-01: Blade views de relatório referenciam colunas dropadas — produz 500 em produção

**Severidade:** BLOCKER
**File:** `resources/views/admin/relatorio-fechamento.blade.php:280, 284, 313-314, 328-329, 360, 363, 367-368, 409-410, 413, 422-423, 435, 439-441`
**File:** `resources/views/admin/relatorio-geral.blade.php:380, 384, 413-414, 428-429, 460, 463, 467-468, 509-510, 512, 521-522, 534, 538-540`
**File:** `resources/views/admin/relatorio-geral-pdf.blade.php:324, 328, 429-430, 432, 437, 448, 453-455`

**Issue:**
O Plan 14-06 dropou as 6 colunas (`service_type`, `contract_type`, `contract_start`, `contract_end`, `additional_service`, `additional_service_price`) e removeu os respectivos casts/fillable de `Company.php`. Porém as 3 Blade views ainda lêem essas propriedades em mais de 50 sites. Exemplos:

```blade
{{-- relatorio-fechamento.blade.php:280 — `contract_type` foi DROPADA --}}
<span>{{ match($company->contract_type ?? '') { 'fixo' => 'Fixo', ... } }}</span>

{{-- linha 284 — `contract_start` e `contract_end` foram DROPADAS --}}
<span>{{ $company->contract_start ? $company->contract_start->format('d/m/Y') . ... }}</span>

{{-- linhas 313-314 — `additional_service_price`, `additional_service` foram DROPADAS --}}
@if ($company->additional_service_price)
    <br>+ R$ {{ number_format($company->additional_service_price,0,',','.') }} ({{ $company->additional_service ?: 'adicional' }})
@endif

{{-- linha 409 — `$v['contract_type']` NÃO está mais no payload de vinculadas --}}
<td>{{ match($v['contract_type'] ?? '') { 'fixo' => 'Fixo', ... } }}</td>
```

Após aplicação da Migration 100003 em produção:
- `$company->contract_type` retorna `null` para MySQL (graças ao `?? ''`), mas `$company->contract_start` retorna `null` → `$company->contract_start->format(...)` lança `Error: Call to a member function format() on null` **antes** do ternário ser avaliado (a expressão `$company->contract_start ?` ainda é truthy se a coluna existir; após drop a property retorna `null` e o ternário entra no else — mas Eloquent pode disparar `Property does not exist` em modo strict).
- Linha 435: `@if ($company->additional_service)` retorna null falsy, OK.
- Linhas 313, 360, 467, 468, 539, 540 chamam `number_format($company->additional_service_price, ...)` dentro de `@if ($company->additional_service_price)` — o `@if` curto-circuita, mas a coluna ainda é acessada e retorna `null` (Eloquent não distingue coluna inexistente de coluna null, apenas registra access). Render OK silenciosamente, mas o relatório fica **incompleto sem informação visível ao usuário**.
- Linha 409, 413, 521 lêem `$v['contract_type']`, `$v['additional_service']` etc. — chaves que **AdminController NÃO popula mais** (verificado em `AdminController.php:521-538` payload de `$vinculadas` e linha 683-700 em `gerarRelatorioGeral`). Resultado: render como `'—'` por causa do `?? ''`, mas o relatório perde dados visíveis.

O Plan 14-05 SUMMARY afirma "6 sites refatorados (2 por Blade view)" — falso. Apenas o site do "Tipo de serviço" foi migrado para `$company->service_type_label`. Os outros 5+ sites por view permanecem legacy.

**Risk:** Empresas em produção que tinham contract_start/end e additional_service_price preenchidos perdem informação ao gerar relatório. Pior: em modo strict (PHP 8.4+ ou `Model::shouldBeStrict()`), acesso a atributo inexistente lança exception — derruba a rota `admin.empresas.relatorio` com 500.

**Fix:**
1. Refatorar os 3 Blade views para usar somente dados do payload novo: `$cobranca_mensal`, `$servicos_contratados_pai` (já existe em `AdminController:561`), `$v['servicos_contratados']` (já existe).
2. Remover blocos `Tipo de contrato` e `Vigência` (ou repensá-los a partir de `contratosServico` agora que data_vencimento mora no contrato individual).
3. Substituir "Serviço adicional" section por iteração sobre contratos não-Faixa (ou simplesmente remover — já listado em `servicos_contratados`).

Exemplo de patch para empresa pai (relatorio-fechamento.blade.php:278-285):
```blade
{{-- REMOVER: bloco contract_type + Vigência (colunas dropadas) --}}
{{-- Substituir por listagem de contratos ativos, se necessário --}}
@if ($servicos_contratados_pai)
<div class="field" style="grid-column:span 2">
    <label>Contratos ativos</label>
    <span>{{ $servicos_contratados_pai }}</span>
</div>
@endif
```

E remover completamente os blocos `@if ($company->additional_service_price)`, `@if ($company->additional_service)`, `@if (!empty($v['additional_service']))`. A informação já está em `$cobranca_mensal` (total) e `$v['servicos_contratados']` (lista).

---

### CR-02: Data migration 100002 quebra em `migrate:fresh` após Plan 14-06

**Severidade:** BLOCKER
**File:** `database/migrations/2026_05_27_100002_migrate_legacy_service_data.php:99-103, 107`

**Issue:**
A migration depende de casts Eloquent que Plan 14-06 removeu de `Company.php`:

```php
// Linha 99-101 — esperando que contract_start tenha cast 'date'
$dataContratacao = $company->contract_start
    ? $company->contract_start->toDateString()
    : $company->created_at->toDateString();

// Linha 103 — idem para contract_end
$dataVencimento = $company->contract_end?->toDateString();

// Linha 107 — esperando que service_type tenha cast 'array'
foreach ((array) $company->service_type as $slug) {
```

Estado atual de `app/Models/Company.php:34-37`:
```php
protected $casts = [
    'active' => 'boolean',
    'status' => 'string',
];
// SEM 'service_type' => 'array', 'contract_start' => 'date', 'contract_end' => 'date'
```

Em produção, esta migration já rodou ANTES do Plan 14-03 remover os casts — funcionou. Em qualquer cenário de fresh install (`php artisan migrate:fresh`, CI, novo VPS, novo dev clonando o repo), a migration rodará com Company.php sem casts:

1. **`$company->service_type`** retorna a string JSON crua do TEXT (ex: `'["polos","gestao"]'`). `(array)` em string PHP gera `['["polos","gestao"]']` — um array de 1 elemento contendo a string JSON inteira. O lookup `$this->mapaLegacy['["polos","gestao"]']` retorna null, `continue` é executado. **Nenhum contrato derivado de `service_type` é criado.**

2. **`$company->contract_start`** retorna a string crua `'2026-01-01'`. `->toDateString()` lança `Error: Call to a member function toDateString() on string`. **Migração crasha** se alguma empresa tiver `contract_start` preenchido.

3. **`$company->additional_service`** é string nativa — funciona. Mas `$company->additional_service_price` com `decimal` sem cast retorna string `"100.00"`; o `(float)` cast na linha 153 absorve isso. OK.

**Risk:** Qualquer fresh deploy ou ambiente de teste novo terá data migration silenciosamente quebrada (nenhum contrato gerado) ou crash. Operações de DR (restore de backup pre-Phase 14 + replay de migrations) impossíveis.

**Fix:**
A migration deve não depender do model com casts presentes. Usar `DB::table` cru ou aplicar parse explícito:

```php
private function migrarContratosLegacy(Company $company, Collection $servicosByNome): void
{
    // Decodifica service_type explicitamente, independente de cast.
    $rawServiceType = DB::table('companies')->where('id', $company->id)->value('service_type');
    $slugs = is_string($rawServiceType) && $rawServiceType !== ''
        ? (json_decode($rawServiceType, true) ?: [])
        : [];

    // Datas: parse via Carbon explicitamente.
    $rawStart = DB::table('companies')->where('id', $company->id)->value('contract_start');
    $rawEnd   = DB::table('companies')->where('id', $company->id)->value('contract_end');

    $dataContratacao = $rawStart
        ? \Carbon\Carbon::parse($rawStart)->toDateString()
        : $company->created_at->toDateString();

    $dataVencimento = $rawEnd
        ? \Carbon\Carbon::parse($rawEnd)->toDateString()
        : null;

    foreach ($slugs as $slug) { /* ... */ }
}
```

Alternativa: criar uma cópia local da Company com `protected $casts` reposto (anonymous class extends Company) — mais invasivo.

---

### CR-03: Job de envio de relatório omite contratos da soma `total_mensalidade`

**Severidade:** BLOCKER
**File:** `app/Jobs/EnviarRelatorioFechamentoJob.php:152`

**Issue:**
```php
$totalMensalidade = ($faixaPai ? $faixaPai['valor'] : 0) + collect($vinculadas)->sum('valor_mensal');
```

O total somado contém apenas o **valor da faixa** (Pai + filhas). Pré-Phase 14, o cálculo somava `additional_service_price` (via chave legacy). Pós-refator, esse componente foi removido **sem ser substituído** pela soma `valor_contratado` dos contratos ativos com `tipo_cobranca = mensal`.

Comparar com `AdminController::gerarRelatorioGeral():707`:
```php
$totalMensalidade = ($cobrancaMensal ?? 0) + collect($vinculadas)->sum('cobranca_mensal');
```

onde `$cobrancaMensal = CobrancaCalculator::novo($faixaPai, $company->contratosServico) ?: null` (faixa + SUM contratos). O Job NÃO usa `CobrancaCalculator::novo` em momento algum — só calcula faixa.

**Risk:** Empresas com contratos extras (ex: Polos R$ 200 mensal + Adicional R$ 500 mensal) recebem por email um total que **subestima** a mensalidade em até R$ 700/mês por empresa. Em fechamento mensal com 20 empresas e adicionais variados, divergência cumulativa pode chegar a milhares de reais — fechamento por email vira documento errado, conflitando com o relatório web. Detectável apenas comparando os 2 outputs manualmente.

Adicionalmente, `valor_mensal` por vinculada (linha 148) também é só faixa, sem contratos: `'valor_mensal' => $fx ? $fx['valor'] : null`. A view Blade do email (`emails/relatorio-fechamento.blade.php`, fora do escopo desta review) recebe payload incompleto.

**Fix:**
1. Importar `App\Support\CobrancaCalculator` no topo do Job.
2. Calcular `cobranca_mensal` por empresa via helper (igual ao controller):
```php
// Linha 148-149: trocar
'valor_mensal' => $fx ? $fx['valor'] : null,
// por
'valor_mensal'    => $fx ? $fx['valor'] : null,
'cobranca_mensal' => CobrancaCalculator::novo($fx, $f->contratosServico) ?: null,

// Linha 152: trocar
$totalMensalidade = ($faixaPai ? $faixaPai['valor'] : 0) + collect($vinculadas)->sum('valor_mensal');
// por
$cobrancaPai = CobrancaCalculator::novo($faixaPai, $company->contratosServico) ?: null;
$totalMensalidade = ($cobrancaPai ?? 0) + collect($vinculadas)->sum('cobranca_mensal');
```
3. Idealmente cobrir com teste: `EnviarRelatorioFechamentoJobTest::test_payload_inclui_contratos_no_total`.

---

## Warnings

### WR-01: Ofuscação de coluna via concat de strings em comando — anti-pattern de grepabilidade

**Severidade:** WARNING
**File:** `app/Console/Commands/Phase14VerificarCobranca.php:55, 88`

**Issue:**
```php
$hasLegacyPrice = Schema::hasColumn('companies', 'additional_' . 'service_price');
// ...
? CobrancaCalculator::legacy($faixaData, (float) ($company->getAttribute('additional_' . 'service_price') ?? 0))
```

A concat literal `'additional_' . 'service_price'` é uma trick deliberada para evadir `grep -E "service_type|additional_service|..."` ao varrer o codebase. Decisão documentada em SUMMARY-06 ("rg ... app/ → 0 matches" como gate de aceitação).

**Por que é problema:**
1. Quem mantiver o código no futuro e fizer `grep additional_service_price app/Console` NÃO encontrará este uso. Risco de bug silencioso quando futuras refatorações tocarem este código.
2. O comando `phase14:verificar-cobranca` deveria ser **deletado** após a Phase 14 fechar (já documentado em RESEARCH §1 e CobrancaCalculator docblock). Manter ofuscação é débito que vira armadilha.
3. O critério "grep limpo" foi cumprido por trapaça em vez de remoção real. Convém ou (a) deletar o comando após drop, ou (b) explicitar a string com comentário grande pt-BR alertando.

**Fix recomendado:** Remover o comando inteiro em commit pós-deploy (já que Plan 14-06 confirma drop concluído e Frente B fechada). Se ficar como histórico, trocar a concat por uma string única com comentário:

```php
// HISTÓRICO Phase 14: a coluna 'additional_service_price' foi dropada em Plan 14-06.
// Esta verificação só roda em ambientes pré-drop (sentinel via Schema::hasColumn).
// Mantida concat de strings deliberadamente para que `grep additional_service_price`
// continue retornando 0 em `app/` — gate de aceitação do drop.
$col = 'additional_service_price';
$hasLegacyPrice = Schema::hasColumn('companies', $col);
```

---

### WR-02: Accessor `service_type_label` não tem guard contra schema pós-drop com loadMissing

**Severidade:** WARNING
**File:** `app/Models/Company.php:62-74`

**Issue:**
```php
public function getServiceTypeLabelAttribute(): string
{
    $this->loadMissing('contratosServico.servico');
    $servicosAtivos = $this->contratosServico
        ->where('ativo', true)
        ->pluck('servico')
        ->filter();
    return static::labelFromServicos($servicosAtivos);
}
```

`loadMissing` em loop de Blade dispara 1 query por empresa se `contratosServico` não estiver eager-loaded. Em `gerarRelatorioGeral` o controller eager-load (`AdminController:606-608`), então OK. Mas qualquer outro caller do accessor (ex: futura iteração sobre `Company::all()`) viola Pitfall 2 do RESEARCH (N+1).

Adicionalmente, se o accessor for invocado em uma `Company` carregada por outro caller (Artisan tinker, debug, novo controller) sem `contratosServico` na query, dispara 2 queries por empresa (contratos + servico). Em loop de 50 empresas: 100 queries silenciosas.

**Risk:** Performance degradation difícil de diagnosticar; bem documentado em RESEARCH §3 Pitfall 2 mas mitigado apenas no AdminController. Outros controllers que cheguem ao accessor pelo Blade (ex: relatórios DEV ou Setor Dev futuro) regressam para N+1.

**Fix:**
1. Documentar no docblock que o accessor PRESSUPÕE eager loading; callers devem fazer `with(['contratosServico' => fn($q) => $q->where('ativo', true)->with('servico')])`.
2. Considerar substituir `loadMissing` por verificação `relationLoaded()` e fallback explícito que NÃO dispare query (retorna `'—'` se não eager-loaded), com `Log::warning('[Phase14] accessor invocado sem eager loading')` para detectar uso inadequado em prod.

```php
public function getServiceTypeLabelAttribute(): string
{
    if (!$this->relationLoaded('contratosServico')) {
        \Log::warning('[Phase14] Company::service_type_label sem eager loading', ['company_id' => $this->id]);
        $this->loadMissing('contratosServico.servico');
    }
    // ... resto igual
}
```

---

### WR-03: Semântica `?: null` zera contratos ativos com valor R$ 0,00 legítimo

**Severidade:** WARNING
**File:** `app/Http/Controllers/AdminController.php:282-284, 520, 542, 682, 705`

**Issue:**
```php
// Linha 282-284
$cobrancaMensal = ($faixaData !== null || $temContratoMensal)
    ? (CobrancaCalculator::novo($faixaData, $c->contratosServico) ?: null)
    : null;

// Linha 520, 542, 682, 705 (similar)
$cobrancaMensalFilha = CobrancaCalculator::novo($fx, $f->contratosServico) ?: null;
```

Cenário: empresa SEM dados Adman (`$faixaData === null`) MAS COM contratos ativos mensais cujo `valor_contratado === 0.00` (caso real: contratos derivados da Migration 100002 com `valor_contratado=0` por D-04 — TODOS os 6 tipos canônicos legacy). `CobrancaCalculator::novo(null, [contratos_zero])` retorna `0.0`. O Elvis `?: null` converte `0.0` → `null`. A UI exibe "—" em vez de "R$ 0,00".

Em escopo limitado do Fechamento atual isso é OK (alinhado com semântica legacy descrita em RESEARCH §3 — null = sem cobrança). Porém:
1. Quando o usuário **EDITAR** um contrato para `valor_contratado=200`, a empresa passa a ter cobrança R$ 200 — agora aparece. Sem o `?: null`, antes apareceria "R$ 0,00", o que é semanticamente mais correto.
2. O `0.0 ?: null` em PHP é uma armadilha histórica: futura assertiva `$cobrancaMensal === 0.0` vai sempre falhar em código que assume float não-null.
3. Em `AdminController:280-281`, `$temContratoMensal` é avaliado por `contains(fn)`, que cria UMA nova iteração da Collection apesar dela já estar filtrada pelo eager load (linha 161 `->where('ativo', true)`). Redundância sem ganho.

**Risk:** Bug latente quando contratos com valor zero forem editados; comportamento divergente entre "tem contrato R$ 0" e "tem contrato R$ 0,01".

**Fix:**
1. Substituir `?: null` por verificação explícita:
```php
$total = CobrancaCalculator::novo($faixaData, $c->contratosServico);
$cobrancaMensal = ($faixaData !== null || $temContratoMensal) ? $total : null;
// (sem `?: null` — preserva 0.0 quando contratos foram criados mas zerados)
```
2. Remover `->where('ativo', true)` redundante em linha 280 (já eager-loaded com filtro).
3. Mesma correção em linhas 520, 542, 682, 705.

---

### WR-04: Docblock partido em `Company.php` — comentários órfãos sem contexto

**Severidade:** WARNING
**File:** `app/Models/Company.php:40-49, 56-61, 167-170`

**Issue:**
Restos de docblocks editados que perderam o contexto da frase introdutória:

```php
// Linha 40-49 — sentença começa no meio da frase
/**
 * Converte uma coleção (ou array) de Servicos em label legível, separados por vírgula.
 * verdade são os contratos ativos da empresa, não os slugs legacy.
 *           ^^^^^^^^ esta linha começa sem início
 * ...
 */

// Linha 56-61 — docblock vazio com pedaços
/**
 *
 * Phase 14 (Frente B): API estática preservada para os callers (Blades e JSX
 * fonte de verdade agora é a coleção `contratosServico` (eager-loaded ou
 *  ^^^^^ idem
 * lazy via `loadMissing`).
 */

// Linha 167-170 — método contratosServico() com docblock incompleto
/**
 * Contratos de serviço da empresa (Módulo Serviços — Frente A).
 *
 */
```

Indica que durante a refatoração algumas frases foram deletadas mas suas linhas-suporte sobreviveram. Não afeta runtime mas degrada legibilidade — futuro mantenedor lê "fonte de verdade agora é a coleção" sem saber do que.

**Fix:** Restaurar frases iniciais ou apagar fragmentos:

```php
/**
 * Converte uma coleção (ou array) de Servicos em label legível, separados por vírgula.
 *
 * Phase 14 (Frente B): a fonte de verdade são os contratos ativos da empresa,
 * não os slugs legacy.
 * Aceita qualquer iterável cujos itens exponham a propriedade `nome` (típico:
 * `Servico` Eloquent ou objeto anônimo nos testes).
 * ...
 */
public static function labelFromServicos(iterable $servicos): string

/**
 * Label legível dos serviços ativos da empresa, derivado de `contratosServico`.
 *
 * Phase 14 (Frente B): API estática preservada para os callers (Blades e JSX);
 * a fonte de verdade agora é a coleção `contratosServico` (eager-loaded ou
 * lazy via `loadMissing`).
 */
public function getServiceTypeLabelAttribute(): string
```

---

## Info

### IN-01: Servico::find sem abort_if dentro de loop de criação atômica

**Severidade:** INFO
**File:** `app/Http/Controllers/ComercialController.php:206-210`

**Issue:**
```php
foreach ($validated['servicos'] as $item) {
    $servico = Servico::find($item['servico_id']);
    if (!$servico) {
        continue;  // <-- silencioso
    }
    // ... cria contrato
}
```

A validação Rule::exists na linha 174 já garante que `servico_id` existe E está com `ativo=true` no momento da validação. Porém, entre a validação e o `find()`, uma race condition extremamente rara poderia desativar/deletar o serviço. O `continue` silencia o erro.

Cenário muito improvável (admin desativa serviço enquanto outra request está no meio do submit), mas o `continue` esconde inconsistência: empresa ficaria criada com MENOS contratos do que o usuário esperava, sem nenhum log.

**Risk:** Baixo. Cenário de race exigiria 2 admins agindo simultaneamente; mitigado por activity log do Servico.

**Fix:** Substituir `continue` por exception explícita que aborta a transação:
```php
$servico = Servico::find($item['servico_id']);
if (!$servico) {
    throw new \RuntimeException("Servico ID {$item['servico_id']} desapareceu entre validação e criação");
}
```
A transaction reverte automaticamente, o usuário recebe 500 e log fica claro.

---

### IN-02: ComercialController::store reusa `$company` por reference em closure sem null-check pós-transaction

**Severidade:** INFO
**File:** `app/Http/Controllers/ComercialController.php:190-291`

**Issue:**
```php
$company = null;
$servicosCriados = collect();

DB::transaction(function () use ($validated, &$company, &$servicosCriados) {
    // ...
    $company = Company::create([...]);
    // ...
});

// Linhas 262-267 — usa $company->name sem checar se a transação fechou de fato
activity('comercial')->withProperties(['empresa' => $company->name, ...])->log(...);
```

Se a transaction lançar exception (qualquer falha em `Company::create` ou `ContratoServico::create`), o `DB::transaction` re-lança a exception e o código após NÃO executa — então `$company` nunca é dereferenciado quando null. **Não é bug real**, apenas inicialização defensiva que confunde a leitura.

**Fix (opcional, melhora clareza):** Mover a inicialização `$company = null` para dentro da função ou usar tipo nullable explícito + assertion:
```php
DB::transaction(function () use (...) {
    $company = Company::create([...]);  // local
    // ...
});
assert($company instanceof Company);  // hint para static analyzer
```

Ou ainda melhor: deixar `DB::transaction` retornar o `$company`:
```php
$company = DB::transaction(function () use ($validated, &$servicosCriados): Company {
    $c = Company::create([...]);
    // ...
    return $c;
});
```

---

### IN-03: Duplicação de tabela FAIXAS entre 3 arquivos (AdminController, EnviarRelatorioFechamentoJob, Phase14VerificarCobranca)

**Severidade:** INFO
**File:** `app/Http/Controllers/AdminController.php:23-30`
**File:** `app/Jobs/EnviarRelatorioFechamentoJob.php:34-41`
**File:** `app/Console/Commands/Phase14VerificarCobranca.php:39-46`

**Issue:**
A constante `FAIXAS` (tabela de progressão de cobrança) é replicada verbatim em 3 arquivos. O comentário em `Phase14VerificarCobranca.php:36-38` diz "Duplicação intencional e temporária: este comando é deletado após a conclusão da Phase 14". OK para o command.

Porém AdminController e EnviarRelatorioFechamentoJob continuam duplicando. Se o time de Finanças mudar valores (ex: faixa_1 de R$ 3.000 para R$ 3.500), há 2 arquivos para editar. RESEARCH §3 sugere extrair para service. O Job tem comentário "espelho intencional de AdminController" mas isso é fragilidade, não pattern.

**Risk:** Drift silencioso entre Job e Controller. Já documentado como aceito na implementação Phase 14, mas aumenta a chance de bugs futuros.

**Fix sugerido (fora de escopo Phase 14, mas recomendado em next-up):**
Extrair `FAIXAS` + métodos `calcularFaixa`/`faixaLabel` para `App\Support\FaixasCobranca`:
```php
class FaixasCobranca
{
    public const FAIXAS = [...];
    public static function calcularFaixa(float $faturamento): array { ... }
    public static function faixaLabel(string $faixa): string { ... }
}
```
Ambos AdminController e Job consomem a fonte única.

---

## Notas adicionais (não findings)

**Pontos positivos** verificados durante a review:
- `CobrancaCalculator::novo` corretamente filtra por `$contrato->ativo`, `$contrato->servico` (não-null) e `tipo_cobranca === TIPO_MENSAL`. Aceita `iterable` para testabilidade pura sem container Laravel. Helper bem desenhado.
- Eager loading em `AdminController::fechamento` (linha 161), `gerarRelatorio` (linha 469-473), `gerarRelatorioGeral` (linha 604-608) e `EnviarRelatorioFechamentoJob` (linha 85-89) — Pitfall 2 do RESEARCH evitado.
- `whereHas('contratosServico', ...)` no `MlbController:1531-1536` e `CompanyController:88-94` — substitui corretamente `whereJsonContains`. Geração de SQL `EXISTS (...)` parametrizada, sem risco de injection. Conformes ao §5 do RESEARCH.
- `ComercialController::store` em `DB::transaction`, validação `Rule::exists('servicos', 'id')->where('ativo', true)` previne mass assignment de serviços inativos (T-14-04 mitigado).
- Migrations 100001 e 100002 idempotentes via `firstOrCreate` (catálogo) e guard `where(...)->exists()` (contratos) — conformes ao §1 RESEARCH.
- Migration 100003 down() recria 6 colunas com tipos exatos (`text` para `service_type`, `decimal(10,2)` para `additional_service_price`) — Pitfall 7 evitado.
- `Comercial/NovaEmpresa.jsx` usa `cn()`, tokens `ecf-yellow`/`ecf-bg`/`ecf-card` conforme CLAUDE.md. Sem `dangerouslySetInnerHTML`. Sem XSS.
- Pesquisa de legacy em `app/`: 0 matches (verificado via `Grep service_type|contract_type|...` em `app/`).
- Pesquisa em `resources/js/`: 0 matches (Plan 14-07 fechado corretamente).
- Convenções pt-BR mantidas em comentários, mensagens de log, `activity('comercial')->log(...)`.

**Itens fora de escopo da review v1** (performance):
- Aglomerado N de contratos por empresa em `chunk(100)` da migration 100002 — irrelevante para correctness; Pitfall 2 já mitigado no caminho principal.
- `Mail::to($destinatarios)->send` em loop de empresas no Job — comportamento Laravel padrão, não regressão Phase 14.

---

_Reviewed: 2026-05-26_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
