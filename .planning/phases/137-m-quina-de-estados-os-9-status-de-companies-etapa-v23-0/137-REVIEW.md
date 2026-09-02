---
phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
reviewed: 2026-09-01T00:00:00Z
depth: deep
files_reviewed: 15
files_reviewed_list:
  - database/migrations/2026_09_01_110000_add_etapa_to_companies_table.php
  - database/migrations/2026_09_01_120000_create_company_etapa_transicoes_table.php
  - database/migrations/2026_09_01_130000_add_pendencia_to_companies_table.php
  - app/Models/Company.php
  - app/Models/CompanyEtapaTransicao.php
  - app/Services/FluxoEntrada/EtapaTransicaoService.php
  - app/Console/Commands/EtapaBackfill.php
  - app/Http/Controllers/CompanyController.php
  - resources/js/Pages/Companies/Index.jsx
  - tests/Unit/Phase137/CompanyEtapaConstantesTest.php
  - tests/Unit/Phase137/EtapaTransicaoServiceTest.php
  - tests/Feature/Phase137/EtapaBackfillTest.php
  - tests/Feature/Phase137/EtapaFiltroListagemTest.php
  - tests/Feature/Phase137/EtapaPendenciaParaleloTest.php
  - tests/Feature/Phase137/EtapaPontoUnicoTest.php
findings:
  critical: 2
  warning: 3
  info: 0
  total: 5
status: issues_found
---

# Phase 137: Code Review Report

**Reviewed:** 2026-09-01
**Depth:** deep (leitura completa + verificação contra o schema real do MariaDB local, `ecf_admin`)
**Files Reviewed:** 15
**Status:** issues_found

## Summary

A fase entrega a fundação da máquina de estados (`companies.etapa`), o histórico
append-only (`company_etapa_transicoes`), a pendência paralela (4 colunas em
`companies`) e o filtro server-side de `/companies`. O desenho geral é sólido:
migrations puramente aditivas, `status` intocado, `EtapaTransicaoService` é de
fato o único chamador de produção capaz de escrever `etapa` hoje, e as 46
provas de `tests/{Unit,Feature}/Phase137` passam localmente (verificado nesta
revisão).

Dois problemas, porém, comprometem diretamente as garantias que a fase se
propôs a fixar:

1. O gate estático de `EtapaPontoUnicoTest.php` — a "prova permanente" do
   Success Criteria nº 2 — tem dois desvios de rota já idiomáticos NESTE
   MESMO repositório que o deixam sem efeito: nome de variável diferente de
   `$company` e escrita via `DB::table('companies')->update(...)` (padrão já
   usado duas vezes no projeto para outras colunas).
2. A FK de `company_etapa_transicoes.user_id` está `ON DELETE CASCADE`
   (confirmado no schema real via `SHOW CREATE TABLE`), enquanto a FK irmã
   `companies.pendencia_por`, criada na MESMA fase, corretamente usa
   `nullOnDelete()`. Como `UserController::forceDestroy()` já existe e faz
   hard-delete de usuário, remover permanentemente UM colaborador apaga em
   cascata o histórico de transição de TODAS as empresas que ele já
   movimentou — o oposto do que o próprio docblock da migration diz querer
   evitar (comparando-se deliberadamente contra a retenção de 365 dias do
   `spatie/activitylog`).

Os demais pontos (migrations, ordem de `dropForeign`/`dropColumn`, o gate de
pendência, os testes unitários da régua `podeTransicionar()`/`transicionar()`,
o allow-list dos filtros) foram auditados e estão corretos.

## Structural Findings (fallow)

Nenhum `<structural_findings>` foi fornecido para esta revisão.

## Narrative Findings (AI reviewer)

## Critical Issues

### CR-01: Gate estático de "ponto único de escrita" (`EtapaPontoUnicoTest`) é contornável por dois caminhos já idiomáticos neste repositório

**File:** `tests/Feature/Phase137/EtapaPontoUnicoTest.php:270-300`
**Issue:**

O Grupo 2 deste teste é descrito no próprio arquivo como a "prova permanente"
de que só `EtapaTransicaoService` escreve `companies.etapa` (Success Criteria
nº 2 da fase). A varredura estática (`descreveViolacao()`) tem dois furos
reais, não hipotéticos:

**(a) Atribuição direta hardcoded para a variável `$company`.** A regra 1 só
casa `\$company->etapa\s*=`. O código deste MESMO projeto usa rotineiramente
outros nomes para uma instância de `Company` — `$empresa`, `$c`, `$emp`, `$f`
— confirmados em produção:
```
app/Http/Controllers/ComercialController.php:252: function (Company $c) use ($pendencias)
app/Http/Controllers/AdminController.php:502:     fn(Company $emp): ?float
app/Console/Commands/NpsReplicarRespostaParaGrupo.php:277: function preverAtribuicoes(Company $empresa, ...)
app/Services/Portal/PortalEquipeService.php:51:   function podeEntrar(User $membro, Company $empresa): bool
```
Um futuro `$empresa->etapa = ...` ou `$c->etapa = Company::ETAPA_X;` dentro de
qualquer arquivo de `app/` passa pelo gate sem ser detectado.

**(b) Escrita via `DB::table('companies')->update([...])` nunca é
avaliada.** A regra 2 só considera "escrita na Company" quando o corpo da
função contém `Company::(create|updateOrCreate|firstOrCreate|forceCreate)`,
`$company->(update|fill|forceFill)`, ou `Company::\w+(...)` combinado com
`->update(`. Nenhum desses padrões casa com `DB::table('companies')->where(...)
->update([...])` — e esse EXATO idioma já existe, hoje, duas vezes no
projeto, para outras colunas:
```
app/Console/Commands/DiagnoseCustId.php:206:
    DB::table('companies')->where('id', $company->id)->update(['adman_account_id' => null]);

app/Console/Commands/ImportMarketplaceFromCsv.php:145:
    DB::table('companies')->where('id', $company->id)->update(['marketplace' => $marketplaceEnum, ...]);
```
Um comando de diagnóstico/fix futuro que siga exatamente esse precedente
(`DB::table('companies')->where('id', $company->id)->update(['etapa' => ...])`)
passaria pelo gate sem acusar nada — mesmo violando o ponto único de escrita
de forma direta e sem transação/histórico.

Nota: `CompanyController::update()` hoje está seguro (a validação não aceita
`etapa`/`pendencia_*` — isso foi conferido e está correto). O problema é que
o mecanismo desenhado para impedir QUALQUER violação futura em `app/`, em
qualquer arquivo, não cobre dois caminhos plausíveis e já usados neste
código-base para a mesma tabela.

**Fix:**
```php
// Regra 1 — não fixar o nome da variável; casar qualquer `->etapa = `
// que não seja `==`/`===`, restrito a arquivos que referenciam Company.
if (preg_match('/\bCompany\b/', $corpo) && preg_match('/->etapa\s*=(?!=)/', $corpo)) {
    // ...
}

// Regra 2 — cobrir DB::table('companies') como escrita legítima de Company,
// além dos padrões Eloquent já cobertos:
$escreveNaCompany =
    preg_match('/Company::(create|updateOrCreate|firstOrCreate|forceCreate)\s*\(/', $corpo) === 1
    || preg_match('/\$company->(update|fill|forceFill)\s*\(/', $corpo) === 1
    || (preg_match('/Company::\w+\s*\(/', $corpo) === 1 && preg_match('/->update\s*\(/', $corpo) === 1)
    || preg_match('/DB::table\(\s*[\'"]companies[\'"]\s*\)/', $corpo) === 1;
```
Considere também escanear `database/migrations/` (fora do escopo atual de
`app/`) já que migrations futuras também podem tocar a coluna via `DB::table`.

---

### CR-02: `company_etapa_transicoes.user_id` com `ON DELETE CASCADE` apaga histórico de auditoria de empresas não relacionadas quando um usuário é removido permanentemente

**File:** `database/migrations/2026_09_01_120000_create_company_etapa_transicoes_table.php:31`
**Issue:**

```php
$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
```

Confirmado no schema real (`SHOW CREATE TABLE company_etapa_transicoes` na
base local `ecf_admin`, que já rodou esta migration):
```
CONSTRAINT `company_etapa_transicoes_user_id_foreign`
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
```

`User` usa `SoftDeletes`, então a maioria das exclusões de usuário
(`UserController::destroy()`) não aciona a FK. Mas `UserController::
forceDestroy()` (linha 436) já existe, é uma rota admin ativa, e faz
`$user->forceDelete()` — hard delete de verdade. `user_id` aqui é o AUTOR de
uma transição, não o "dono" do registro: um único colaborador pode ter
movimentado a etapa de dezenas de empresas diferentes ao longo do tempo.
Remover permanentemente esse UM usuário apaga em cascata TODAS as linhas de
`company_etapa_transicoes` em que ele foi o ator — para empresas que não
estão sendo excluídas, sem qualquer aviso, confirmação ou log adicional.

Isso contradiz diretamente o próprio racional documentado no topo desta
migration: a tabela existe (em vez de usar `spatie/activitylog`) exatamente
porque `config('activitylog.delete_records_older_than_days') = 365` "é risco
desalinhado com o propósito do dado" que a Fase 143 usa para medir SLA. O
`ON DELETE CASCADE` no ator reintroduz o mesmo risco por outra porta.

Compare com a FK irmã, criada NA MESMA FASE, para o mesmo relacionamento
conceitual (ator de uma ação em `companies`) — que acertou o desenho:
```
-- database/migrations/2026_09_01_130000_add_pendencia_to_companies_table.php:46-47
$table->foreignId('pendencia_por')->nullable()->after('pendencia_motivo')
    ->constrained('users')->nullOnDelete();
```
Confirmado também no schema real: `companies_pendencia_por_foreign ...
ON DELETE SET NULL`.

**Fix:** trocar para `nullOnDelete()` (exige tornar `user_id` nullable) —
preserva a linha de histórico (o "quando" e o "de/para" continuam
auditáveis) e só perde a referência ao ator, igual ao precedente
`pendencia_por` na mesma fase:
```php
$table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
```
Alternativa, se o ator nunca puder faltar por regra de negócio:
`restrictOnDelete()` — bloqueia o `forceDestroy()` de qualquer usuário que já
tenha atuado numa transição, o que ao menos falha ruidosamente em vez de
apagar dado em silêncio. Requer nova migration (a coluna já está em
produção com `ON DELETE CASCADE` assim que este código for deployado).

## Warnings

### WR-01: `EtapaTransicaoService::transicionar()` decide e escreve sem lock — race condition latente para quando a Fase 138 conectar chamadores concorrentes (ex.: webhook)

**File:** `app/Services/FluxoEntrada/EtapaTransicaoService.php:181-232`
**Issue:** `podeTransicionar()` é avaliado sobre `$company->etapa` em memória
ANTES de `DB::transaction()` abrir, e a closure da transação reutiliza o
mesmo objeto `$company` sem `lockForUpdate()` nem `refresh()`. Duas chamadas
concorrentes para a mesma empresa (ex.: um webhook do Clicksign reenviado por
timeout, ou duplo-clique num botão "Finalizar" antes do primeiro round-trip
terminar) podem ambas ler o mesmo `etapa` "antigo", ambas passar em
`podeTransicionar()`, e ambas escrever — quebrando o invariante que o
docblock da classe declara ("não existe etapa mudada sem linha de
histórico" pressupõe uma única escrita por vez) e potencialmente gravando
uma transição que a tabela `TRANSICOES_PERMITIDAS` nunca autorizaria se lida
de forma serializada.

O projeto já tem convenção estabelecida para exatamente esta classe de
problema (leitura-decide-escreve num estado compartilhado):
```
app/Services/Desempenho/CompanyScoreSnapshotWriter.php:68:  ->lockForUpdate()
app/Http/Controllers/DesempenhoMetricasManuaisController.php:180: ->lockForUpdate()
```
Hoje isto é latente — o próprio docblock do serviço afirma corretamente que
não há chamador de produção nesta fase. Mas a Fase 138 (webhook → etapa 1) é
descrita no mesmo arquivo como o primeiro chamador real, e webhooks são
justamente a superfície mais sujeita a reentrega/retry concorrente.

**Fix:** mover a leitura para dentro da transação com lock, e reavaliar
`podeTransicionar()` sobre o estado travado:
```php
DB::transaction(function () use ($company, $etapaDestino, $por, $motivo) {
    $travada = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();

    $avaliacao = $this->podeTransicionar($travada, $etapaDestino);
    if (! $avaliacao['permitido']) {
        // ... early return com status 'recusado', dentro da transação
    }
    // ... resto da escrita usando $travada
});
```

---

### WR-02: `EtapaBackfill` usa `companies.name` (não único) como chave de array — colisão descarta silenciosamente uma empresa do balde 1

**File:** `app/Console/Commands/EtapaBackfill.php:72-77`
**Issue:**
```php
$idsBalde1 = Company::query()
    ->where(function ($q) {
        $q->whereHas('analistaPerformance')
            ->orWhereHas('estrategistaPerformance');
    })
    ->pluck('id', 'name');
```
`companies.name` não tem `unique()` no schema (`database/migrations/
2026_04_26_152217_create_companies_table.php:16` — só `string('name')`, sem
`->unique()`; confirmado também via `SHOW CREATE TABLE` local). `pluck('id',
'name')` usa `name` como CHAVE do array — se duas empresas do balde 1
tiverem o mesmo `name` (nada no schema impede isso), a segunda sobrescreve a
primeira no `Collection`, e a primeira desaparece silenciosamente do lote
que `carimbarBackfill()` recebe. Essa empresa fica com `etapa = NULL` em vez
de `em_operacao`, mesmo satisfazendo o critério do balde 1.

O comando não teria como perceber isso sozinho: `contar()` só compara totais
antes/depois, não o conjunto de ids, então a disciplina de reconsulta (D-08,
já documentada no próprio arquivo) não pega esse caso a menos que alguém
compare o `SELECT DISTINCT name, COUNT(*) ... HAVING COUNT(*) > 1` também.
Não há colisão na base local hoje (verificado: 0 nomes duplicados em 180
empresas), mas nada no schema impede que apareça nas ~500 de produção, e o
comando roda com `--apply` diretamente contra ela.

Note que isso não quebra a aba "Empresas" (Success Criteria nº 1) porque
aquela tela ainda decide visibilidade por `em_operacao` derivado, não por
`etapa` — o efeito fica confinado ao novo filtro `?etapa=` e à futura leitura
de `etapa` como fonte (Fase 142), mas é real e silencioso.

**Fix:**
```php
$idsBalde1 = Company::query()
    ->where(fn ($q) => $q->whereHas('analistaPerformance')->orWhereHas('estrategistaPerformance'))
    ->pluck('id'); // chave numérica, sem risco de colisão

// amostra do dry-run, separada, só para exibição:
$amostra = Company::query()
    ->whereIn('id', $idsBalde1->take(20))
    ->get(['id', 'name']);
```

---

### WR-03: `Companies/Index.jsx` — os handlers de filtro não preservam os filtros pré-existentes entre si; escolher etapa/pendência apaga `cust_id_status` (e vice-versa)

**File:** `resources/js/Pages/Companies/Index.jsx:198-228`
**Issue:** `aplicarEtapaFilter()` e `aplicarComPendenciaFilter()` (novos
nesta fase) montam `params` só com `etapa` e `com_pendencia`, sem incluir
`cust_id_status` (filtro pré-existente). E `aplicarCustIdFilter()`
(pré-existente, não tocado nesta fase) monta `params` só com
`cust_id_status`, sem incluir `etapa`/`com_pendencia`. Resultado: usar
qualquer um desses controles limpa silenciosamente os outros já ativos —
por exemplo, um admin filtrando "Cust ID Inválido" que em seguida escolhe
uma etapa no novo `<select>` perde o filtro de Cust ID sem nenhum aviso.

Isso contradiz o próprio comentário acrescentado por esta fase logo acima de
`aplicarEtapaFilter`:
```js
// Cada handler PRESERVA o outro filtro já ativo — um não pode apagar o outro (D-23).
```
— verdadeiro apenas para o par etapa/pendência (D-23 cobre só esses dois),
não para a interação com `cust_id_status`, que ficou de fora. O backend já
suporta os três combinados (`CompanyController::index()` encadeia os três
`when()` sem conflito, coberto por
`tests/Feature/Phase137/EtapaFiltroListagemTest.php`) — a limitação é
puramente do lado do cliente.

**Fix:** centralizar a leitura dos filtros ativos e reenviar todos a cada
mudança, por exemplo:
```js
const aplicarFiltros = (overrides) => {
    const params = {
        ...(custIdStatusFilter ? { cust_id_status: custIdStatusFilter } : {}),
        ...(etapaFilter ? { etapa: etapaFilter } : {}),
        ...(comPendenciaFilter ? { com_pendencia: 1 } : {}),
        ...overrides,
    };
    router.get(route('companies.index'), params, { preserveState: true, preserveScroll: true });
};
// aplicarCustIdFilter = (v) => aplicarFiltros({ cust_id_status: v || undefined });
// aplicarEtapaFilter  = (v) => aplicarFiltros({ etapa: v || undefined });
// aplicarComPendenciaFilter = (ativo) => aplicarFiltros({ com_pendencia: ativo ? 1 : undefined });
```

---

_Reviewed: 2026-09-01_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
